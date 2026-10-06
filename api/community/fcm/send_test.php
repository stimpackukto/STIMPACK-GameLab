<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/fcm_common.php';

function fcm_test_json(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'ok'      => $success ? 1 : 0,
        'message' => $message,
        'msg'     => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fcm_test_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function fcm_test_post_form(string $url, array $fields): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'errno'     => $errno,
        'error'     => $error,
        'body'      => $body === false ? '' : (string)$body,
    ];
}

function fcm_test_post_json(string $url, array $headers, array $payload): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'errno'     => $errno,
        'error'     => $error,
        'body'      => $body === false ? '' : (string)$body,
    ];
}

function fcm_test_load_service_account(): array
{
    if (!defined('FCM_SERVICE_ACCOUNT_JSON') || FCM_SERVICE_ACCOUNT_JSON === '') {
        fcm_test_json(false, 'FCM_SERVICE_ACCOUNT_JSON 설정이 없습니다.');
    }

    $path = (string)FCM_SERVICE_ACCOUNT_JSON;

    if (!is_file($path)) {
        fcm_test_json(false, 'Firebase 서비스 계정 JSON 파일을 찾지 못했습니다.', [
            'path' => $path,
        ]);
    }

    $json = file_get_contents($path);

    if ($json === false || trim($json) === '') {
        fcm_test_json(false, 'Firebase 서비스 계정 JSON 파일을 읽지 못했습니다.', [
            'path' => $path,
        ]);
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        fcm_test_json(false, 'Firebase 서비스 계정 JSON 파싱에 실패했습니다.');
    }

    foreach (['project_id', 'client_email', 'private_key'] as $key) {
        if (empty($data[$key]) || !is_string($data[$key])) {
            fcm_test_json(false, 'Firebase 서비스 계정 JSON 값이 올바르지 않습니다.', [
                'missing' => $key,
            ]);
        }
    }

    return $data;
}

function fcm_test_access_token(array $serviceAccount): string
{
    $now = time();

    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
    ];

    $claim = [
        'iss'   => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $unsignedJwt =
        fcm_test_b64url(json_encode($header, JSON_UNESCAPED_SLASHES)) .
        '.' .
        fcm_test_b64url(json_encode($claim, JSON_UNESCAPED_SLASHES));

    $signature = '';

    $ok = openssl_sign(
        $unsignedJwt,
        $signature,
        $serviceAccount['private_key'],
        OPENSSL_ALGO_SHA256
    );

    if (!$ok) {
        fcm_test_json(false, 'Firebase JWT 서명에 실패했습니다.');
    }

    $jwt = $unsignedJwt . '.' . fcm_test_b64url($signature);

    $res = fcm_test_post_form('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);

    $decoded = json_decode($res['body'], true);

    if ($res['errno'] !== 0 || $res['http_code'] < 200 || $res['http_code'] >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
        fcm_test_json(false, 'Firebase OAuth 토큰 발급에 실패했습니다.', [
            'http_code' => $res['http_code'],
            'curl_errno' => $res['errno'],
            'curl_error' => $res['error'],
            'response' => $res['body'],
        ]);
    }

    return (string)$decoded['access_token'];
}

function fcm_test_latest_device(mysqli $conn): ?array
{
    $communityDb = fcm_ident(FCM_COMMUNITY_DB);

    $accountId = (int)($_GET['account_id'] ?? $_POST['account_id'] ?? 0);

    if ($accountId > 0) {
        $sql = "
            SELECT id, account_id, device_id, fcm_token, app_version, platform, is_active, last_seen_at, created_at
            FROM {$communityDb}.`fcm_device_token`
            WHERE is_active = 1
              AND account_id = ?
            ORDER BY id DESC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            fcm_test_json(false, '토큰 조회 준비에 실패했습니다.', [
                'error' => $conn->error,
            ]);
        }

        $stmt->bind_param('i', $accountId);
        $stmt->execute();

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        $stmt->close();

        return $row ?: null;
    }

    $sql = "
        SELECT id, account_id, device_id, fcm_token, app_version, platform, is_active, last_seen_at, created_at
        FROM {$communityDb}.`fcm_device_token`
        WHERE is_active = 1
        ORDER BY id DESC
        LIMIT 1
    ";

    $res = $conn->query($sql);

    if (!$res) {
        fcm_test_json(false, '토큰 조회에 실패했습니다.', [
            'error' => $conn->error,
        ]);
    }

    $row = $res->fetch_assoc();

    return $row ?: null;
}

function fcm_test_send_message(string $projectId, string $accessToken, string $targetToken, string $title, string $body): array
{
    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';

    $payload = [
        'message' => [
            'token' => $targetToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'data' => [
                'type' => 'test',
                'source' => 'stimpack_fcm_test',
                'sent_at' => date('Y-m-d H:i:s'),
            ],
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'sound' => 'default',
                    'channel_id' => 'default',
                ],
            ],
        ],
    ];

    return fcm_test_post_json($url, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json; charset=utf-8',
    ], $payload);
}

$secret = trim((string)($_GET['secret'] ?? $_POST['secret'] ?? ''));

if (!defined('FCM_TEST_SECRET') || FCM_TEST_SECRET === '') {
    fcm_test_json(false, 'FCM_TEST_SECRET 설정이 없습니다.');
}

if (!hash_equals((string)FCM_TEST_SECRET, $secret)) {
    fcm_test_json(false, '테스트 권한이 없습니다.');
}

$conn = fcm_db();

$device = fcm_test_latest_device($conn);

if (!$device) {
    fcm_test_json(false, '발송할 활성 FCM 토큰이 없습니다.');
}

$targetToken = trim((string)$device['fcm_token']);

if ($targetToken === '') {
    fcm_test_json(false, 'DB의 FCM 토큰이 비어 있습니다.');
}

$serviceAccount = fcm_test_load_service_account();
$projectId = (string)$serviceAccount['project_id'];

$title = trim((string)($_GET['title'] ?? $_POST['title'] ?? 'Stimpack FCM 테스트'));
$body = trim((string)($_GET['body'] ?? $_POST['body'] ?? 'FCM 발송 테스트입니다.'));

if ($title === '') {
    $title = 'Stimpack FCM 테스트';
}

if ($body === '') {
    $body = 'FCM 발송 테스트입니다.';
}

$accessToken = fcm_test_access_token($serviceAccount);

$sendResult = fcm_test_send_message($projectId, $accessToken, $targetToken, $title, $body);

$decoded = json_decode($sendResult['body'], true);

if ($sendResult['errno'] !== 0 || $sendResult['http_code'] < 200 || $sendResult['http_code'] >= 300) {
    fcm_test_json(false, 'FCM 발송에 실패했습니다.', [
        'http_code' => $sendResult['http_code'],
        'curl_errno' => $sendResult['errno'],
        'curl_error' => $sendResult['error'],
        'firebase_response' => $decoded ?: $sendResult['body'],
        'device' => [
            'id' => (int)$device['id'],
            'account_id' => (int)$device['account_id'],
            'device_id' => (string)$device['device_id'],
            'app_version' => (string)$device['app_version'],
            'platform' => (string)$device['platform'],
            'last_seen_at' => (string)$device['last_seen_at'],
        ],
    ]);
}

fcm_test_json(true, 'FCM 테스트 발송 완료', [
    'firebase_response' => $decoded ?: $sendResult['body'],
    'device' => [
        'id' => (int)$device['id'],
        'account_id' => (int)$device['account_id'],
        'device_id' => (string)$device['device_id'],
        'app_version' => (string)$device['app_version'],
        'platform' => (string)$device['platform'],
        'last_seen_at' => (string)$device['last_seen_at'],
    ],
]);