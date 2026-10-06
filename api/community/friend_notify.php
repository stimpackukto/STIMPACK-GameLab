<?php
// /api/community/friend_notify.php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $accountId = community_require_session();
    $ownerGuid = (int)community_post('character_guid');
    $targetGuid = (int)community_post('target_guid');
    $enabled = (int)community_post('enabled') === 1 ? 1 : 0;
    community_check_character_owner($accountId, $ownerGuid);

    $app = community_app_db();
    $stmt = $app->prepare("\n        INSERT INTO friend_notify_settings (owner_guid, target_guid, enabled, updated_at)\n        VALUES (?, ?, ?, NOW())\n        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = VALUES(updated_at)\n    ");
    $stmt->bind_param('iii', $ownerGuid, $targetGuid, $enabled);
    $stmt->execute();
    $stmt->close();
    $app->close();

    community_json(['ok' => true, 'message' => '저장되었습니다.']);
} catch (Throwable $e) {
    community_json(['ok' => false, 'message' => '친구 접속 알림 설정 오류: ' . $e->getMessage()]);
}
