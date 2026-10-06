<?php
// /api/community/app_forum_list.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

app_current_account();
$input = app_input();
$category = trim((string)($input['category'] ?? 'notice'));
$appDb = db_conn(db_name('app'));

$posts = [];
if (table_exists($appDb, 'app_board_posts')) {
    if ($category === 'all') {
        $posts = fetch_all_safe($appDb, "SELECT id, category, title, LEFT(REPLACE(REPLACE(body, '\\r', ''), '\\n', ' '), 160) AS summary, created_at
            FROM app_board_posts
            WHERE is_visible=1
            ORDER BY created_at DESC LIMIT 50");
    } else {
        $posts = fetch_all_safe($appDb, "SELECT id, category, title, LEFT(REPLACE(REPLACE(body, '\\r', ''), '\\n', ' '), 160) AS summary, created_at
            FROM app_board_posts
            WHERE category=? AND is_visible=1
            ORDER BY created_at DESC LIMIT 50", 's', [$category]);
    }
}

app_json(true, '게시글 목록 조회 완료', ['posts' => $posts]);
