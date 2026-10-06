<?php
// /api/community/app_push_insert.php
// 운영자/크론에서 앱 알림을 쌓을 때 사용하는 내부용 샘플이다.
// 웹 공개 경로에 그대로 둘 경우 반드시 Nginx allow/deny 또는 별도 관리자 토큰을 추가해야 한다.

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';

$input = app_input();
$adminKey = getenv('STIMPACK_APP_ADMIN_KEY') ?: '';
if ($adminKey === '' || !hash_equals($adminKey, (string)($input['admin_key'] ?? ''))) {
    app_json(false, '권한이 없습니다.');
}

$accountId = (int)($input['account_id'] ?? 0);
$title = trim((string)($input['title'] ?? ''));
$body = trim((string)($input['body'] ?? ''));
if ($accountId <= 0 || $title === '' || $body === '') {
    app_json(false, 'account_id, title, body가 필요합니다.');
}

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
$stmt = $db->prepare('INSERT INTO app_notifications(account_id, title, body) VALUES (?, ?, ?)');
$stmt->bind_param('iss', $accountId, $title, $body);
$stmt->execute();

app_json(true, '알림 저장 완료', ['id' => $db->insert_id]);
