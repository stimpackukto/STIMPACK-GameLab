<?php
// /api/community/mark_alert_read.php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $accountId = community_require_session();
    $type = community_post('alert_type');
    $app = community_app_db();

    if ($type === '') {
        $stmt = $app->prepare('UPDATE app_alerts SET is_read = 1, read_at = NOW() WHERE account_id = ? AND is_read = 0');
        $stmt->bind_param('i', $accountId);
    } else {
        $stmt = $app->prepare('UPDATE app_alerts SET is_read = 1, read_at = NOW() WHERE account_id = ? AND alert_type = ? AND is_read = 0');
        $stmt->bind_param('is', $accountId, $type);
    }

    $stmt->execute();
    $stmt->close();
    $app->close();

    community_json(['ok' => true, 'message' => '읽음 처리되었습니다.']);
} catch (Throwable $e) {
    community_json(['ok' => false, 'message' => '알림 읽음 처리 오류: ' . $e->getMessage()]);
}
