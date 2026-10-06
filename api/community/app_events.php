<?php
// /api/community/app_events.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

app_current_account();
$appDb = db_conn(db_name('app'));

$events = [];
if (table_exists($appDb, 'app_events')) {
    $events = fetch_all_safe($appDb, "SELECT id, title, description, starts_at, ends_at
        FROM app_events
        WHERE is_active=1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())
        ORDER BY id DESC LIMIT 20");
}
if (!$events) {
    $events = [[
        'title' => '진행 중인 이벤트 API 연결 대기',
        'description' => 'app_events 테이블 또는 기존 홈페이지 이벤트 테이블을 매핑하면 표시됩니다.',
    ]];
}

$missions = [];
if (table_exists($appDb, 'app_daily_missions')) {
    $missions = fetch_all_safe($appDb, "SELECT id, title, description, reward_text, starts_at, ends_at
        FROM app_daily_missions
        WHERE is_active=1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())
        ORDER BY id DESC LIMIT 20");
}

app_json(true, '이벤트 조회 완료', [
    'events' => $events,
    'missions' => $missions,
]);
