<?php
// /api/community/app_forum_write.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$me = app_current_account();
$input = app_input();
$category = trim((string)($input['category'] ?? 'free'));
$title = trim((string)($input['title'] ?? ''));
$body = trim((string)($input['body'] ?? ''));

if ($title === '' || $body === '') {
    app_json(false, '제목과 내용을 입력하세요.');
}
if (mb_strlen($title) > 120) {
    app_json(false, '제목은 120자 이내로 입력하세요.');
}

$db = db_conn(db_name('app'));
$db->query("CREATE TABLE IF NOT EXISTS app_board_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(40) NOT NULL DEFAULT 'free',
    account_id INT UNSIGNED NOT NULL,
    account_name VARCHAR(64) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    is_visible TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX idx_category(category, is_visible, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $db->prepare('INSERT INTO app_board_posts(category, account_id, account_name, title, body) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('sisss', $category, $me['account_id'], $me['account_name'], $title, $body);
$stmt->execute();

app_json(true, '게시글 작성 완료', ['id' => $db->insert_id]);
