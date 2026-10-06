<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(E_ALL);

/*
 * FCM API 전용 공통 설정
 * - /etc/trinity-web.env 를 직접 읽는다.
 * - 기존 DB 테이블만 사용한다.
 * - C++ SendFcmCategory() GET 호출과 맞춘다.
 */
if (!function_exists('fcm_load_server_env')) {
    function fcm_load_server_env(): array
    {
        static $env = null;

        if ($env !== null) {
            return $env;
        }

        $file = '/etc/trinity-web.env';

        if (!is_file($file)) {
            $env = [];
            return $env;
        }

        $parsed = parse_ini_file($file, false, INI_SCANNER_RAW);
        $env = is_array($parsed) ? $parsed : [];

        return $env;
    }
}

if (!function_exists('server_env')) {
    function server_env(string $key, $default = null)
    {
        $env = fcm_load_server_env();

        if (isset($env[$key]) && $env[$key] !== '') {
            return $env[$key];
        }

        $systemValue = getenv($key);

        if ($systemValue !== false && $systemValue !== '') {
            return $systemValue;
        }

        return $default;
    }
}

if (!defined('DB_HOST')) {
    define('DB_HOST', (string)server_env('DB_HOST', '127.0.0.1'));
}

if (!defined('DB_PORT')) {
    define('DB_PORT', (int)server_env('DB_PORT', 3306));
}

if (!defined('DB_USER')) {
    define('DB_USER', (string)server_env('DB_USER', ''));
}

if (!defined('DB_PASS')) {
    define('DB_PASS', (string)server_env('DB_PASS', ''));
}

if (!defined('DB_NAME')) {
    define('DB_NAME', (string)server_env('DB_NAME', 'auth'));
}

if (!defined('FCM_COMMUNITY_DB')) {
    define('FCM_COMMUNITY_DB', (string)server_env('FCM_COMMUNITY_DB', 'lightguardian_community'));
}

if (!defined('FCM_AUTH_DB')) {
    define('FCM_AUTH_DB', (string)server_env('FCM_AUTH_DB', 'auth'));
}

if (!defined('FCM_TEST_SECRET')) {
    define('FCM_TEST_SECRET', (string)server_env('FCM_TEST_SECRET', ''));
}

if (!defined('FCM_SERVICE_ACCOUNT_JSON')) {
    define('FCM_SERVICE_ACCOUNT_JSON', (string)server_env('FCM_SERVICE_ACCOUNT_JSON', ''));
}

function fcm_json(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'ok'      => $success ? 1 : 0,
        'message' => $message,
        'msg'     => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fcm_ident(string $name): string
{
    $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'lightguardian_community';
    return '`' . $name . '`';
}

function fcm_db(): mysqli
{
    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $user = defined('DB_USER') ? DB_USER : '';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    $name = defined('DB_NAME') ? DB_NAME : FCM_COMMUNITY_DB;
    $port = defined('DB_PORT') ? (int)DB_PORT : 3306;

    $conn = new mysqli($host, $user, $pass, $name, $port);

    if ($conn->connect_errno) {
        fcm_json(false, 'DB 연결에 실패했습니다.');
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

function fcm_upper_latin(string $value): string
{
    return (string)preg_replace_callback('/[a-z]/', static function (array $m): string {
        return strtoupper($m[0]);
    }, $value);
}

function fcm_bin_field(string $value): string
{
    $trimmed = trim($value);

    if (strlen($trimmed) === 64 && ctype_xdigit($trimmed)) {
        $bin = hex2bin($trimmed);
        return $bin === false ? $value : $bin;
    }

    return $value;
}

function fcm_srp6_check_login(string $username, string $password, string $saltBin, string $verifierDb): bool
{
    if (!function_exists('gmp_init')) {
        error_log('FCM_LOGIN_GMP_MISSING');
        return false;
    }

    $g = gmp_init(7);
    $N = gmp_init('894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7', 16);

    $username = fcm_upper_latin($username);
    $password = fcm_upper_latin($password);

    $hash1 = sha1($username . ':' . $password, true);
    $xHash = sha1($saltBin . $hash1, true);
    $x = gmp_import($xHash, 1, GMP_LSW_FIRST);

    $v = gmp_powm($g, $x, $N);
    $verifierCalc = gmp_export($v, 1, GMP_LSW_FIRST);
    $verifierCalc = str_pad($verifierCalc, 32, "\0", STR_PAD_RIGHT);

    return hash_equals($verifierCalc, $verifierDb);
}

function fcm_api_secret(): string
{
    if (defined('FCM_API_SECRET') && FCM_API_SECRET !== '') {
        return (string)FCM_API_SECRET;
    }

    if (defined('APP_SECRET') && APP_SECRET !== '') {
        return (string)APP_SECRET;
    }

    if (defined('COOKIE_SECRET') && COOKIE_SECRET !== '') {
        return (string)COOKIE_SECRET;
    }

    return hash('sha256', __DIR__ . '|' . php_uname('n'));
}

function fcm_make_auth_token(int $accountId, string $username, string $verifierBin): string
{
    return hash_hmac(
        'sha256',
        $accountId . '|' . fcm_upper_latin($username) . '|' . bin2hex($verifierBin),
        fcm_api_secret()
    );
}

if (!function_exists('fcm_random_token')) {
    function fcm_random_token(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            return hash('sha256', uniqid('', true) . '|' . microtime(true));
        }
    }
}

if (!function_exists('fcm_issue_session')) {
    function fcm_issue_session(mysqli $conn, int $accountId, string $deviceId): string
    {
        $communityDb = fcm_ident(FCM_COMMUNITY_DB);
        $token = fcm_random_token();
        $expiresAt = date('Y-m-d H:i:s', time() + 2592000);

        $sql = "
            INSERT INTO {$communityDb}.`fcm_api_session`
                (account_id, device_id, auth_token, expires_at, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                auth_token = VALUES(auth_token),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('FCM_SESSION_PREP_FAIL: ' . $conn->error);
            fcm_json(false, '세션 저장에 실패했습니다.');
        }

        $stmt->bind_param('isss', $accountId, $deviceId, $token, $expiresAt);

        if (!$stmt->execute()) {
            error_log('FCM_SESSION_EXEC_FAIL: ' . $stmt->error);
            $stmt->close();
            fcm_json(false, '세션 저장에 실패했습니다.');
        }

        $stmt->close();
        return $token;
    }
}

if (!function_exists('fcm_require_session')) {
    function fcm_require_session(mysqli $conn): array
    {
        $accountId = (int)($_POST['account_id'] ?? $_GET['account_id'] ?? 0);
        $authToken = trim((string)($_POST['auth_token'] ?? $_GET['auth_token'] ?? ''));

        if ($accountId <= 0 || $authToken === '') {
            fcm_json(false, '로그인이 필요합니다.');
        }

        $communityDb = fcm_ident(FCM_COMMUNITY_DB);
        $sql = "
            SELECT account_id, device_id, auth_token
            FROM {$communityDb}.`fcm_api_session`
            WHERE account_id = ?
              AND auth_token = ?
              AND expires_at > NOW()
            LIMIT 1
        ";

        try {
            $stmt = $conn->prepare($sql);
        } catch (Throwable $e) {
            error_log('FCM_SESSION_CHECK_PREP_THROW: ' . $e->getMessage());
            $stmt = false;
        }

        if ($stmt) {
            $stmt->bind_param('is', $accountId, $authToken);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($row) {
                return [
                    'account_id' => (int)$row['account_id'],
                    'device_id' => (string)($row['device_id'] ?? ''),
                    'auth_token' => (string)$row['auth_token'],
                ];
            }
        } else {
            error_log('FCM_SESSION_CHECK_PREP_FAIL: ' . $conn->error);
        }

        if (function_exists('fcm_require_auth')) {
            return fcm_require_auth($conn);
        }

        fcm_json(false, '로그인이 만료되었습니다.');
    }
}

function fcm_load_account_by_id(mysqli $conn, int $accountId): ?array
{
    $authDb = fcm_ident(FCM_AUTH_DB);
    $sql = "
        SELECT id, username, salt, verifier
        FROM {$authDb}.`account`
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('FCM_ACCOUNT_ID_PREP_FAIL: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function fcm_require_auth(mysqli $conn): array
{
    $accountId = (int)($_POST['account_id'] ?? $_GET['account_id'] ?? 0);
    $authToken = trim((string)($_POST['auth_token'] ?? $_GET['auth_token'] ?? ''));

    if ($accountId <= 0 || $authToken === '') {
        fcm_json(false, '로그인이 필요합니다.');
    }

    $row = fcm_load_account_by_id($conn, $accountId);

    if (!$row) {
        fcm_json(false, '계정을 찾지 못했습니다.');
    }

    $username = (string)($row['username'] ?? '');
    $verifier = fcm_bin_field((string)($row['verifier'] ?? ''));

    if ($username === '' || strlen($verifier) !== 32) {
        fcm_json(false, '계정 인증 정보가 올바르지 않습니다.');
    }

    $expected = fcm_make_auth_token($accountId, $username, $verifier);

    if (!hash_equals($expected, $authToken)) {
        fcm_json(false, '로그인이 만료되었습니다.');
    }

    return [
        'account_id' => $accountId,
        'username'   => $username,
        'auth_token' => $authToken,
    ];
}

function fcm_save_device_token(mysqli $conn, int $accountId, string $deviceId, string $fcmToken, string $appVersion = '', string $platform = 'android'): void
{
    if ($accountId <= 0 || $deviceId === '' || $fcmToken === '') {
        return;
    }

    $communityDb = fcm_ident(FCM_COMMUNITY_DB);
    $sql = "
        INSERT INTO {$communityDb}.`fcm_device_token`
            (account_id, device_id, fcm_token, app_version, platform, is_active, last_seen_at, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            account_id = VALUES(account_id),
            fcm_token = VALUES(fcm_token),
            app_version = VALUES(app_version),
            platform = VALUES(platform),
            is_active = 1,
            last_seen_at = NOW(),
            updated_at = NOW()
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('FCM_DEVICE_TOKEN_PREP_FAIL: ' . $conn->error);
        return;
    }

    $stmt->bind_param('issss', $accountId, $deviceId, $fcmToken, $appVersion, $platform);

    if (!$stmt->execute()) {
        error_log('FCM_DEVICE_TOKEN_EXEC_FAIL: ' . $stmt->error);
    }

    $stmt->close();
}

function fcm_save_push_token(mysqli $conn, int $accountId, string $fcmToken, string $deviceInfo = ''): void
{
    if ($accountId <= 0 || $fcmToken === '') {
        return;
    }

    $communityDb = fcm_ident(FCM_COMMUNITY_DB);
    $sql = "
        INSERT INTO {$communityDb}.`push_tokens` (account_id, fcm, device_info, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            account_id = VALUES(account_id),
            fcm = VALUES(fcm),
            device_info = VALUES(device_info),
            updated_at = VALUES(updated_at)
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('FCM_PUSH_TOKEN_PREP_FAIL: ' . $conn->error);
        return;
    }

    $stmt->bind_param('iss', $accountId, $fcmToken, $deviceInfo);

    if (!$stmt->execute()) {
        error_log('FCM_PUSH_TOKEN_EXEC_FAIL: ' . $stmt->error);
    }

    $stmt->close();
}

if (!function_exists('fcm_soap_settings')) {
    function fcm_soap_settings(): array
    {
        global $soap_config;

        $host = defined('SOAP_HOST') ? (string)SOAP_HOST : '';
        $port = defined('SOAP_PORT') ? (int)SOAP_PORT : 7878;
        $username = defined('SOAP_USER') ? (string)SOAP_USER : (defined('SOAP_USERNAME') ? (string)SOAP_USERNAME : '');
        $password = defined('SOAP_PASS') ? (string)SOAP_PASS : (defined('SOAP_PASSWORD') ? (string)SOAP_PASSWORD : '');

        if ($host === '' && isset($soap_config['host'])) {
            $host = (string)$soap_config['host'];
        }

        if ($username === '' && isset($soap_config['username'])) {
            $username = (string)$soap_config['username'];
        }

        if ($password === '' && isset($soap_config['password'])) {
            $password = (string)$soap_config['password'];
        }

        if ($host === '' && function_exists('server_env')) {
            $host = (string)server_env('SOAP_HOST', '127.0.0.1');
        }

        if ($username === '' && function_exists('server_env')) {
            $username = (string)server_env('SOAP_USER', (string)server_env('SOAP_USERNAME', ''));
        }

        if ($password === '' && function_exists('server_env')) {
            $password = (string)server_env('SOAP_PASS', (string)server_env('SOAP_PASSWORD', ''));
        }

        if ($host === '') {
            $host = '127.0.0.1';
        }

        if ($port <= 0) {
            $port = 7878;
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ];
    }
}

if (!function_exists('fcm_execute_soap_command')) {
    function fcm_execute_soap_command($command, $port = 7878): array
    {
        $command = (string)$command;
        $settings = fcm_soap_settings();
        $host = (string)$settings['host'];
        $port = (int)$port > 0 ? (int)$port : (int)$settings['port'];
        $username = (string)$settings['username'];
        $password = (string)$settings['password'];

        if ($command === '') {
            return ['sent' => false, 'message' => 'SOAP 명령어가 비어 있습니다.'];
        }

        if ($username === '' || $password === '') {
            return ['sent' => false, 'message' => 'SOAP 계정 정보가 설정되어 있지 않습니다.'];
        }

        if (class_exists('SoapClient')) {
            try {
                $connection = new SoapClient(null, [
                    'location' => 'http://' . $host . ':' . $port,
                    'uri' => 'urn:TC',
                    'login' => $username,
                    'password' => $password,
                    'keep_alive' => false,
                ]);

                $connection->executeCommand(new SoapParam($command, 'command'));
                unset($connection);

                return ['sent' => true, 'message' => '성공적으로 전송됨'];
            } catch (Throwable $e) {
                if (isset($connection)) {
                    unset($connection);
                }

                return ['sent' => false, 'message' => $e->getMessage()];
            }
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:ns1="urn:TC">'
            . '<SOAP-ENV:Body>'
            . '<ns1:executeCommand>'
            . '<command>' . htmlspecialchars($command, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</command>'
            . '</ns1:executeCommand>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "urn:TC#executeCommand"',
            'Authorization: Basic ' . base64_encode($username . ':' . $password),
            'Content-Length: ' . strlen($body),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://' . $host . ':' . $port . '/', false, $context);

        if ($response === false) {
            return ['sent' => false, 'message' => 'SOAP 서버에 연결할 수 없습니다.'];
        }

        if (
            stripos($response, 'faultstring') !== false ||
            stripos($response, 'Command failed') !== false ||
            stripos($response, 'Authentication failed') !== false
        ) {
            return ['sent' => false, 'message' => 'SOAP 명령 실행 실패', 'raw' => $response];
        }

        return ['sent' => true, 'message' => '성공적으로 전송됨', 'raw' => $response];
    }
}
