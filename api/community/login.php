<?php
// /api/community/login.php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function community_srp6_check_login(string $username, string $password, string $salt_bin, string $verifier_db): bool
{
    if (!function_exists('gmp_init')) {
        throw new RuntimeException('PHP GMP 확장이 필요합니다.');
    }

    $g = gmp_init(7);
    $N = gmp_init('894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7', 16);
    $username = preg_replace_callback('/[a-z]/', fn($m) => strtoupper($m[0]), $username);
    $password = preg_replace_callback('/[a-z]/', fn($m) => strtoupper($m[0]), $password);
    $hash1 = sha1($username . ':' . $password, true);
    $xHash = sha1($salt_bin . $hash1, true);
    $x = gmp_import($xHash, 1, GMP_LSW_FIRST);
    $v = gmp_powm($g, $x, $N);
    $verifier_calc = gmp_export($v, 1, GMP_LSW_FIRST);
    $verifier_calc = str_pad($verifier_calc, 32, "\0", STR_PAD_RIGHT);

    return hash_equals($verifier_calc, $verifier_db);
}

$username = community_post_first(['username', 'account']);
$password = community_post('password');
$fcmToken = community_post('fcm_token');
$deviceId = community_post('device_id');
$deviceInfo = community_post('device_info', $deviceId);

if ($username === '' || $password === '') {
    community_json(['ok' => false, 'message' => '아이디 또는 비밀번호가 비어 있습니다.']);
}

try {
    $conn = community_db();
    $stmt = $conn->prepare('SELECT id, username, salt, verifier FROM auth.account WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->close();
        community_json(['ok' => false, 'message' => '존재하지 않는 계정입니다.']);
    }

    $accountId = (int)$row['id'];
    $accountName = (string)$row['username'];

    if (!community_srp6_check_login($accountName, $password, (string)$row['salt'], (string)$row['verifier'])) {
        $conn->close();
        community_json(['ok' => false, 'message' => '비밀번호가 올바르지 않습니다.']);
    }

    $sessionToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + ((int)COMMUNITY_SESSION_DAYS * 86400));
    $schema = community_app_schema_sql();

    $stmt = $conn->prepare("INSERT INTO {$schema}.app_sessions (account_id, session_token, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $accountId, $sessionToken, $expiresAt);
    $stmt->execute();
    $stmt->close();

    if ($deviceId !== '') {
        $stmt = $conn->prepare("\n            INSERT INTO {$schema}.app_devices (account_id, device_id, fcm_token, device_info, updated_at)\n            VALUES (?, ?, ?, ?, NOW())\n            ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token), device_info = VALUES(device_info), updated_at = VALUES(updated_at)\n        ");
        $stmt->bind_param('isss', $accountId, $deviceId, $fcmToken, $deviceInfo);
        $stmt->execute();
        $stmt->close();
    }

    $conn->close();

    community_json([
        'ok' => true,
        'message' => '로그인 성공',
        'account_id' => $accountId,
        'username' => $accountName,
        'session_token' => $sessionToken,
        'expires_at' => $expiresAt,
    ]);
} catch (Throwable $e) {
    community_json(['ok' => false, 'message' => '로그인 확인 API 오류: ' . $e->getMessage()]);
}
