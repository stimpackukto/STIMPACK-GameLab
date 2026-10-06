<?php
// /api/community/lib/auth.php

// 기존 홈페이지 로그인 방식에 맞춰 auth.account 의 salt/verifier(SRP6)로 로그인 확인한다.
// 기존 홈페이지 파일은 수정하지 않고 앱 API 로그인 검증만 변경한다.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function app_sql_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('허용되지 않는 DB 식별자입니다: ' . $name);
    }
    return '`' . $name . '`';
}

function app_auth_schema(): string
{
    return (string)db_name('auth');
}

function app_auth_table(): string
{
    return app_sql_ident(app_auth_schema()) . '.`account`';
}

function app_tables(): void
{
    $db = db_conn(db_name('app'));

    $db->query("CREATE TABLE IF NOT EXISTS app_mobile_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        account_name VARCHAR(64) NOT NULL,
        token CHAR(64) NOT NULL UNIQUE,
        device_id VARCHAR(128) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        last_seen_at DATETIME NULL,
        INDEX idx_account_id(account_id),
        INDEX idx_expires_at(expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 기존 홈페이지의 push_tokens 구조를 앱 전용 DB(lightguardian_community) 안에 별도로 만든다.
    $db->query("CREATE TABLE IF NOT EXISTS push_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        fcm TEXT NOT NULL,
        device_info VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_account_fcm(account_id, fcm(191)),
        INDEX idx_account_id(account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 앱 전용 기기 토큰 테이블도 유지한다. push_tokens가 있으면 로그인 API는 push_tokens를 우선 사용한다.
    $db->query("CREATE TABLE IF NOT EXISTS app_device_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        device_id VARCHAR(128) NOT NULL,
        fcm_token TEXT NOT NULL,
        platform VARCHAR(32) NOT NULL DEFAULT 'android',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_device(account_id, device_id),
        INDEX idx_account_id(account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * 기존 홈페이지 로그인 코드와 동일한 TrinityCore 규칙.
 * username/password 전체를 strtoupper() 하지 않고 라틴 소문자(a-z)만 대문자로 치환한다.
 */
function app_tc_ascii_upper(string $value): string
{
    return (string)preg_replace_callback('/[a-z]/', static fn(array $m): string => strtoupper($m[0]), $value);
}

/**
 * salt/verifier 컬럼이 BINARY(32), VARBINARY(32), BLOB 또는 HEX 문자열인 경우를 모두 흡수한다.
 */
function app_tc_binary_32($value): string
{
    if ($value === null) {
        return '';
    }

    $bin = (string)$value;
    if (strlen($bin) === 32) {
        return $bin;
    }

    $trimmed = trim($bin);
    if (strlen($trimmed) === 64 && ctype_xdigit($trimmed)) {
        $decoded = hex2bin($trimmed);
        return $decoded === false ? '' : $decoded;
    }

    return $bin;
}

/**
 * TrinityCore SRP6 체크. 기존 홈페이지 로그인 코드의 srp6CheckLogin()과 계산 순서를 맞췄다.
 */
function app_srp6_check_login(string $username, string $password, string $saltBin, string $verifierDb): bool
{
    if (!function_exists('gmp_init')) {
        throw new RuntimeException('PHP GMP 확장이 없습니다. sudo apt install php-gmp 후 php-fpm/nginx 재시작이 필요합니다.');
    }

    $saltBin = app_tc_binary_32($saltBin);
    $verifierDb = app_tc_binary_32($verifierDb);

    if (strlen($saltBin) !== 32 || strlen($verifierDb) !== 32) {
        throw new RuntimeException('auth.account salt/verifier 길이가 32바이트가 아닙니다. salt=' . strlen($saltBin) . ', verifier=' . strlen($verifierDb));
    }

    $g = gmp_init(7);
    $N = gmp_init('894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7', 16);

    // 기존 홈페이지와 동일: 라틴 소문자만 대문자로 치환
    $username = app_tc_ascii_upper($username);
    $password = app_tc_ascii_upper($password);

    $hash1 = sha1($username . ':' . $password, true);
    $xHash = sha1($saltBin . $hash1, true);
    $x = gmp_import($xHash, 1, GMP_LSW_FIRST);

    $v = gmp_powm($g, $x, $N);
    $verifierCalc = gmp_export($v, 1, GMP_LSW_FIRST);
    if ($verifierCalc === false) {
        return false;
    }

    $verifierCalc = str_pad($verifierCalc, 32, "\0", STR_PAD_RIGHT);
    return hash_equals($verifierCalc, $verifierDb);
}

function trinity_sha_pass_hash(string $username, string $password): string
{
    return strtoupper(sha1(strtoupper($username) . ':' . strtoupper($password)));
}

function app_auth_conn(): mysqli
{
    // 계정 확인은 기존 로그인처럼 auth.account를 기준으로 한다.
    // DB_NAME_AUTH가 없으면 auth DB로 접속한다.
    return db_conn(app_auth_schema());
}

function app_account_columns(mysqli $auth): array
{
    $cols = [];
    $res = $auth->query('SHOW COLUMNS FROM ' . app_auth_table());
    while ($row = $res->fetch_assoc()) {
        $cols[(string)$row['Field']] = true;
    }
    return $cols;
}

function app_login_account(string $username, string $password): ?array
{
    $auth = app_auth_conn();
    $username = trim($username);

    if ($username === '' || $password === '') {
        return null;
    }

    $cols = app_account_columns($auth);
    $accountTable = app_auth_table();

    // 현재 홈페이지 방식: auth.account username 조회 + salt/verifier 확인
    if (isset($cols['salt'], $cols['verifier'])) {
        $stmt = $auth->prepare('SELECT id, username, salt, verifier FROM ' . $accountTable . ' WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // 앱 입력에서 대소문자가 달라도 계정은 찾을 수 있게 보조 조회만 추가한다.
        // verifier 계산은 기존 로그인 규칙을 따라 사용자가 입력한 username 기준으로 먼저 검사한다.
        if (!$row) {
            $usernameUpper = app_tc_ascii_upper($username);
            $stmt = $auth->prepare('SELECT id, username, salt, verifier FROM ' . $accountTable . ' WHERE UPPER(username) = ? LIMIT 1');
            $stmt->bind_param('s', $usernameUpper);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$row) {
            return null;
        }

        $accountName = (string)$row['username'];
        $salt = app_tc_binary_32($row['salt'] ?? '');
        $verifier = app_tc_binary_32($row['verifier'] ?? '');

        // 1차: 기존 홈페이지와 동일하게 사용자가 입력한 username으로 계산
        $ok = app_srp6_check_login($username, $password, $salt, $verifier);

        // 2차: 보조 조회로 찾은 경우 DB username 기준도 한 번 더 허용
        if (!$ok && $accountName !== '' && $accountName !== $username) {
            $ok = app_srp6_check_login($accountName, $password, $salt, $verifier);
        }

        if (!$ok) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'username' => $accountName !== '' ? $accountName : $username,
        ];
    }

    // 혹시 구형 sha_pass_hash 서버일 때만 fallback.
    if (isset($cols['sha_pass_hash'])) {
        $usernameUpper = strtoupper($username);
        $stmt = $auth->prepare('SELECT id, username, sha_pass_hash FROM ' . $accountTable . ' WHERE UPPER(username)=? LIMIT 1');
        $stmt->bind_param('s', $usernameUpper);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $expected = trinity_sha_pass_hash((string)$row['username'], $password);
        $stored = strtoupper((string)($row['sha_pass_hash'] ?? ''));
        if ($stored === '' || !hash_equals($stored, $expected)) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
        ];
    }

    throw new RuntimeException(app_auth_schema() . '.account 에 salt/verifier 또는 sha_pass_hash 컬럼이 없습니다.');
}

function app_issue_token(int $accountId, string $accountName, string $deviceId = ''): string
{
    app_tables();

    $config = community_app_config();
    $days = (int)($config['token_days'] ?? 30);
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + ($days * 86400));

    $db = db_conn(db_name('app'));
    $stmt = $db->prepare('INSERT INTO app_mobile_sessions(account_id, account_name, token, device_id, expires_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $accountId, $accountName, $token, $deviceId, $expires);
    $stmt->execute();
    $stmt->close();

    return $token;
}

function app_current_account(): array
{
    app_tables();
    $input = app_input();
    $token = trim((string)($input['token'] ?? $_GET['token'] ?? ''));
    if ($token === '') {
        app_json(false, '로그인이 필요합니다.', [], 200);
    }

    $db = db_conn(db_name('app'));
    $stmt = $db->prepare('SELECT account_id, account_name FROM app_mobile_sessions WHERE token=? AND expires_at > NOW() LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        app_json(false, '로그인이 만료되었습니다.', [], 200);
    }

    $stmt = $db->prepare('UPDATE app_mobile_sessions SET last_seen_at=NOW() WHERE token=?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();

    return [
        'account_id' => (int)$row['account_id'],
        'account_name' => (string)$row['account_name'],
    ];
}
