<?php
// /api/community/app_auction.php

declare(strict_types=1);

require_once __DIR__ . '/lib/feature.php';

app_future_feature(
    '경매 알림',
    '내 입찰 상태, 상회 입찰, 낙찰, 유찰, 수령 가능 상태를 알려주는 기능입니다.',
    [
        ['title' => '상태', 'body' => '차후 추가'],
        ['title' => '연결 방식', 'body' => '기존 서버 DB 또는 홈페이지 테이블을 확인한 뒤 이 API에 매핑하면 앱 화면이 바로 표시됩니다.'],
    ]
);
