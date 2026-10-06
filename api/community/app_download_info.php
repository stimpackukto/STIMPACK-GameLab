<?php
// /api/community/app_download_info.php

declare(strict_types=1);

require_once __DIR__ . '/lib/feature.php';

app_ready_feature('다운로드 안내', '앱 내부 다운로드 대신 PC 브라우저에서 다운로드하도록 안내합니다.', [
    ['title' => '클라이언트/런처', 'body' => 'PC 브라우저에서 https://stimpack.pe.kr/download 접속 후 다운로드'],
    ['title' => '패치파일', 'body' => '압축 해제 후 wow 폴더 Data/koKR 폴더에 넣어 적용'],
    ['title' => '앱 정책', 'body' => '앱에서는 대용량 파일 다운로드를 직접 처리하지 않고 안내만 표시'],
]);
