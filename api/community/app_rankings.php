<?php
// /api/community/app_rankings.php

declare(strict_types=1);

require_once __DIR__ . '/lib/feature.php';

app_future_feature(
    '랭킹',
    '접속, 이벤트, 수집, 전장, 공격대 참여 랭킹을 확인하는 기능입니다.',
    [
        ['title' => '상태', 'body' => '차후 추가'],
        ['title' => '연결 방식', 'body' => '기존 서버 DB 또는 홈페이지 테이블을 확인한 뒤 이 API에 매핑하면 앱 화면이 바로 표시됩니다.'],
    ]
);
