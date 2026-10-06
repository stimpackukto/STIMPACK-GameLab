<?php
// /api/community/app_event_predict.php

declare(strict_types=1);

require_once __DIR__ . '/lib/feature.php';

app_future_feature(
    '예측 이벤트',
    '월드컵 등 예측형 이벤트 참여와 결과 확인 기능입니다.',
    [
        ['title' => '상태', 'body' => '차후 추가'],
        ['title' => '연결 방식', 'body' => '기존 서버 DB 또는 홈페이지 테이블을 확인한 뒤 이 API에 매핑하면 앱 화면이 바로 표시됩니다.'],
    ]
);
