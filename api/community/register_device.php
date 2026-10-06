<?php
// /api/community/register_device.php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $accountId = community_require_session();
    $deviceId = community_post('device_id');
    $fcmToken = community_post('fcm_token');
    $deviceInfo = community_post('device_info');

    if ($deviceId === '') {
        community_json(['ok' => false, 'message' => 'device_id가 없습니다.']);
    }

    $app = community_app_db();
    $stmt = $app->prepare("\n        INSERT INTO app_devices (account_id, device_id, fcm_token, device_info, updated_at)\n        VALUES (?, ?, ?, ?, NOW())\n        ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token), device_info = VALUES(device_info), updated_at = VALUES(updated_at)\n    ");
    $stmt->bind_param('isss', $accountId, $deviceId, $fcmToken, $deviceInfo);
    $stmt->execute();
    $stmt->close();
    $app->close();

    community_json(['ok' => true, 'message' => '기기 정보가 저장되었습니다.']);
} catch (Throwable $e) {
    community_json(['ok' => false, 'message' => '기기 등록 오류: ' . $e->getMessage()]);
}
