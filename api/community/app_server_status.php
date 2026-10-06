<?php
// /api/community/app_server_status.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

app_current_account();

$online = 0;
$characters = 0;
$warnings = [];

try {
    $charDb = db_conn(db_name('characters'));
    if (table_exists($charDb, 'characters')) {
        $onlineRow = fetch_one_safe($charDb, 'SELECT COUNT(*) AS cnt FROM characters WHERE online=1');
        $charCountRow = fetch_one_safe($charDb, 'SELECT COUNT(*) AS cnt FROM characters');
        $online = (int)($onlineRow['cnt'] ?? 0);
        $characters = (int)($charCountRow['cnt'] ?? 0);
    } else {
        $warnings[] = 'characters 테이블 연결 대기.';
    }
} catch (Throwable $e) {
    $warnings[] = '캐릭터 DB 연결/조회 대기: ' . $e->getMessage();
}

app_json(true, '서버 상태 조회 완료', [
    'title' => '서버 상태',
    'status_label' => '운영',
    'description' => '현재 서버 상태와 캐릭터 통계를 표시합니다.',
    'items' => [
        ['label' => '상태', 'value' => 'LIVE'],
        ['label' => '현재 접속자', 'value' => (string)$online],
        ['label' => '생성 캐릭터', 'value' => (string)$characters],
    ],
    'warnings' => $warnings,
]);
