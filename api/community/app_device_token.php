<?php
// /api/community/app_device_token.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$me = app_current_account();
$input = app_input();
$deviceId = trim((string)($input['device_id'] ?? ''));
$fcmToken = trim((string)($input['fcm_token'] ?? ''));

if ($deviceId === '' || $fcmToken === '') {
    app_json(false, 'device_id와 fcm_token이 필요합니다.');
}

$db = db_conn(db_name('app'));
$stmt = $db->prepare('INSERT INTO app_device_tokens(account_id, device_id, fcm_token, platform) VALUES (?, ?, ?, "android") ON DUPLICATE KEY UPDATE fcm_token=VALUES(fcm_token), updated_at=NOW()');
$stmt->bind_param('iss', $me['account_id'], $deviceId, $fcmToken);
$stmt->execute();

app_json(true, '기기 토큰 저장 완료');
