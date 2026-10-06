<?php
// /api/community/app_home.php

// 홈 대시보드.
// 기존 홈페이지/게임 DB 쿼리가 아직 완전히 매핑되지 않았거나 일부 테이블이 없어도
// 앱 홈이 죽지 않도록 기본값과 "차후 추가" 안내를 함께 내려준다.

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$me = app_current_account();

$warnings = [];

$online = 0;
$charCount = 0;
$mainCharacterText = '미설정';

try {
    $charDb = db_conn(db_name('characters'));

    if (table_exists($charDb, 'characters')) {
        $onlineRow = fetch_one_safe($charDb, 'SELECT COUNT(*) AS cnt FROM characters WHERE online=1');
        $charCountRow = fetch_one_safe($charDb, 'SELECT COUNT(*) AS cnt FROM characters');
        $mainCharRow = fetch_one_safe(
            $charDb,
            'SELECT name, level FROM characters WHERE account=? ORDER BY level DESC, name ASC LIMIT 1',
            'i',
            [$me['account_id']]
        );

        $online = (int)($onlineRow['cnt'] ?? 0);
        $charCount = (int)($charCountRow['cnt'] ?? 0);
        if ($mainCharRow) {
            $mainCharacterText = (string)$mainCharRow['name'] . ' Lv.' . (string)$mainCharRow['level'];
        }
    } else {
        $warnings[] = 'characters DB에 characters 테이블을 찾지 못했습니다.';
    }
} catch (Throwable $e) {
    $warnings[] = '캐릭터 DB 연결/조회 대기: ' . $e->getMessage();
}

$notices = [];
$missions = [];
$notifications = [];

try {
    $appDb = db_conn(db_name('app'));

    if (table_exists($appDb, 'app_board_posts')) {
        $notices = fetch_all_safe($appDb, "SELECT id, title, LEFT(REPLACE(REPLACE(body, '\\r', ''), '\\n', ' '), 140) AS summary, created_at
            FROM app_board_posts
            WHERE category IN ('notice','patch') AND is_visible=1
            ORDER BY created_at DESC LIMIT 5");
    } else {
        $warnings[] = '앱 공지 테이블 app_board_posts 생성 대기.';
    }

    if (table_exists($appDb, 'app_daily_missions')) {
        $missions = fetch_all_safe($appDb, "SELECT title, description, reward_text, starts_at, ends_at
            FROM app_daily_missions
            WHERE is_active=1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())
            ORDER BY id DESC LIMIT 5");
    } else {
        $warnings[] = '오늘의 수행 테이블 app_daily_missions 생성 대기.';
    }

    if (table_exists($appDb, 'app_notifications')) {
        $notifications = fetch_all_safe(
            $appDb,
            'SELECT id, title, body, created_at FROM app_notifications WHERE account_id=? ORDER BY created_at DESC LIMIT 5',
            'i',
            [$me['account_id']]
        );
    } else {
        $warnings[] = '알림 테이블 app_notifications 생성 대기.';
    }
} catch (Throwable $e) {
    $warnings[] = '앱 DB 연결/조회 대기: ' . $e->getMessage();
}

if (!$missions) {
    $missions = [[
        'title' => '오늘의 수행 API 연결 대기',
        'description' => 'lightguardian_community.app_daily_missions 또는 기존 오늘의 수행 테이블을 매핑하면 앱 홈에 표시됩니다.',
        'reward_text' => '',
        'starts_at' => null,
        'ends_at' => null,
    ]];
}

if (!$notices) {
    $notices = [[
        'id' => 0,
        'title' => '공지 API 연결 대기',
        'summary' => 'lightguardian_community.app_board_posts 또는 기존 홈페이지 공지 테이블을 매핑하면 최근 공지가 표시됩니다.',
        'created_at' => null,
    ]];
}

app_json(true, '홈 조회 완료', [
    'server_status' => [
        'state' => 'LIVE',
        'online' => $online,
        'characters' => $charCount,
    ],
    'me' => [
        'account_id' => $me['account_id'],
        'account_name' => $me['account_name'],
        'main_character' => $mainCharacterText,
        'rp' => '0',
        'point' => '0',
    ],
    'missions' => $missions,
    'notices' => $notices,
    'notifications' => $notifications,
    'warnings' => $warnings,
]);
