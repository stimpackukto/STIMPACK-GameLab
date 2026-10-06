<?php
declare(strict_types=1);

require_once __DIR__ . '/fcm_common.php';

$conn = fcm_db();
$authDb = fcm_ident(FCM_AUTH_DB);

$username = trim((string)($_POST['username'] ?? $_POST['account'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$deviceId = trim((string)($_POST['device_id'] ?? ''));
$fcmToken = trim((string)($_POST['fcm_token'] ?? ''));
$deviceInfo = trim((string)($_POST['device_info'] ?? $deviceId));

if ($username === '' || $password === '') {
    fcm_json(false, '아이디 또는 비밀번호가 비어 있습니다.');
}

if ($deviceId === '') {
    $deviceId = 'unknown_' . substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 16);
}

$sql = "
    SELECT id, username, salt, verifier
    FROM {$authDb}.`account`
    WHERE username = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('FCM_LOGIN_ACC_PREPARE_FAIL: ' . $conn->error);
    fcm_json(false, '시스템 오류(ACC_PREPARE_FAIL).');
}

$stmt->bind_param('s', $username);
$stmt->execute();
$rs = $stmt->get_result();

if (!$rs || $rs->num_rows !== 1) {
    $stmt->close();
    fcm_json(false, '존재하지 않는 계정입니다.');
}

$row = $rs->fetch_assoc();
$stmt->close();

$accountId = (int)($row['id'] ?? 0);
$dbUsername = (string)($row['username'] ?? $username);
$salt = fcm_bin_field((string)($row['salt'] ?? ''));
$verifier = fcm_bin_field((string)($row['verifier'] ?? ''));

if ($accountId <= 0 || strlen($salt) !== 32 || strlen($verifier) !== 32) {
    fcm_json(false, '계정 인증 정보가 올바르지 않습니다.');
}

if (!fcm_srp6_check_login($dbUsername, $password, $salt, $verifier)) {
    fcm_json(false, '비밀번호가 일치하지 않습니다.');
}

$authToken = fcm_make_auth_token($accountId, $dbUsername, $verifier);

if ($fcmToken !== '') {
    fcm_save_device_token($conn, $accountId, $deviceId, $fcmToken, '', 'android');
    fcm_save_push_token($conn, $accountId, $fcmToken, $deviceInfo);
}

fcm_json(true, '로그인되었습니다.', [
    'account_id'   => $accountId,
    'auth_token'   => $authToken,
    'display_name' => $dbUsername,
]);
