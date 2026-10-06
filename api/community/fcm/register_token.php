<?php
declare(strict_types=1);

require_once __DIR__ . '/fcm_common.php';

$conn = fcm_db();
$session = fcm_require_auth($conn);

$accountId = (int)$session['account_id'];
$deviceId = trim((string)($_POST['device_id'] ?? ''));
$fcmToken = trim((string)($_POST['fcm_token'] ?? ''));
$appVersion = trim((string)($_POST['app_version'] ?? ''));
$platform = trim((string)($_POST['platform'] ?? 'android'));
$deviceInfo = trim((string)($_POST['device_info'] ?? $deviceId));

if ($deviceId === '') {
    fcm_json(false, 'device_id 값이 없습니다.');
}

if ($fcmToken === '') {
    fcm_json(false, 'fcm_token 값이 없습니다.');
}

if ($platform === '') {
    $platform = 'android';
}

fcm_save_device_token($conn, $accountId, $deviceId, $fcmToken, $appVersion, $platform);
fcm_save_push_token($conn, $accountId, $fcmToken, $deviceInfo);

fcm_json(true, '토큰이 저장되었습니다.');
