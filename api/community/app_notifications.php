<?php
// /api/community/app_notifications.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$me = app_current_account();
$db = db_conn(db_name('app'));
$db->query("CREATE TABLE IF NOT EXISTS app_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    is_read TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account(account_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$rows = fetch_all_safe($db, 'SELECT id, title, body, is_read, created_at FROM app_notifications WHERE account_id=? ORDER BY created_at DESC LIMIT 30', 'i', [$me['account_id']]);
app_json(true, '알림 조회 완료', ['notifications' => $rows]);
