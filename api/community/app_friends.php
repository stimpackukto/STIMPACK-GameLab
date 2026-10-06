<?php
// /api/community/app_friends.php

declare(strict_types=1);

require_once __DIR__ . '/lib/feature.php';

app_future_feature(
    '친구 접속 알림',
    '등록한 친구 캐릭터의 접속/종료 상태를 푸시 알림으로 받는 기능입니다.',
    [
        ['title' => '상태', 'body' => '차후 추가'],
        ['title' => '연결 방식', 'body' => '기존 서버 DB 또는 홈페이지 테이블을 확인한 뒤 이 API에 매핑하면 앱 화면이 바로 표시됩니다.'],
    ]
);
