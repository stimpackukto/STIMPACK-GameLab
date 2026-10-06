<?php
// /api/community/app_forum_read.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

app_current_account();
$input = app_input();
$id = (int)($input['id'] ?? 0);
if ($id <= 0) {
    app_json(false, '게시글 ID가 필요합니다.');
}

$appDb = db_conn(db_name('app'));
if (!table_exists($appDb, 'app_board_posts')) {
    app_json(false, '게시판 테이블이 없습니다.');
}

$post = fetch_one_safe($appDb, 'SELECT id, category, title, body, created_at FROM app_board_posts WHERE id=? AND is_visible=1 LIMIT 1', 'i', [$id]);
if (!$post) {
    app_json(false, '게시글을 찾을 수 없습니다.');
}

app_json(true, '게시글 조회 완료', ['post' => $post]);
