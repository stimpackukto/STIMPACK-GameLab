<?php
// /api/community/app_missions.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

app_current_account();
$appDb = db_conn(db_name('app'));
$missions = [];
if (table_exists($appDb, 'app_daily_missions')) {
    $rows = fetch_all_safe($appDb, "SELECT id, title, description, reward_text, starts_at, ends_at
        FROM app_daily_missions
        WHERE is_active=1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())
        ORDER BY id DESC LIMIT 30");
    foreach ($rows as $row) {
        $missions[] = [
            'title' => (string)$row['title'],
            'body' => trim((string)$row['description'] . "\n보상: " . (string)($row['reward_text'] ?? '')),
        ];
    }
}
if (!$missions) {
    $missions[] = ['title' => '오늘의 수행 연결 대기', 'body' => 'app_daily_missions 또는 기존 수행 테이블을 매핑하면 표시됩니다.'];
}

app_json(true, '오늘의 수행 조회 완료', [
    'future' => false,
    'status_label' => '운영',
    'title' => '오늘의 수행',
    'description' => '오늘 진행 가능한 수행 목록입니다.',
    'items' => $missions,
]);
