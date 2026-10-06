<?php
// /api/community/app_characters.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$me = app_current_account();
$characters = [];
$warnings = [];

try {
    $charDb = db_conn(db_name('characters'));
    if (table_exists($charDb, 'characters')) {
        $characters = fetch_all_safe($charDb, 'SELECT guid, name, race, class, gender, level, online, zone, money
            FROM characters
            WHERE account=?
            ORDER BY level DESC, name ASC', 'i', [$me['account_id']]);
    } else {
        $warnings[] = 'characters 테이블 연결 대기.';
    }
} catch (Throwable $e) {
    $warnings[] = '캐릭터 DB 연결/조회 대기: ' . $e->getMessage();
}

foreach ($characters as &$c) {
    $money = (int)($c['money'] ?? 0);
    $c['gold'] = number_format(intdiv($money, 10000));
    $c['online'] = ((int)($c['online'] ?? 0)) === 1;
    $c['race_name'] = '종족 ' . (string)($c['race'] ?? '');
    $c['class_name'] = '직업 ' . (string)($c['class'] ?? '');
}
unset($c);

app_json(true, '캐릭터 조회 완료', [
    'title' => '내 캐릭터',
    'status_label' => '운영',
    'description' => '계정에 연결된 캐릭터 목록입니다.',
    'characters' => $characters,
    'warnings' => $warnings,
]);
