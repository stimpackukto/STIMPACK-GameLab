<?php
// /api/community/lib/feature.php
// 앱 전체 메뉴에서 아직 실제 서버 DB 매핑 전인 기능은 "차후 추가" 상태로 응답한다.

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function app_future_feature(string $title, string $description, array $items = [], array $nextSteps = []): void
{
    app_current_account();
    app_json(true, $title . ' 조회 완료', [
        'future' => true,
        'status_label' => '차후 추가',
        'title' => $title,
        'description' => $description,
        'items' => $items,
        'next_steps' => $nextSteps ?: [
            '기존 홈페이지/게임 DB 테이블 구조 확인',
            '/api/community/ 전용 JSON 응답 매핑',
            '앱 화면의 차후 추가 표시를 운영 표시로 전환',
        ],
    ]);
}

function app_ready_feature(string $title, string $description, array $items = []): void
{
    app_current_account();
    app_json(true, $title . ' 조회 완료', [
        'future' => false,
        'status_label' => '운영',
        'title' => $title,
        'description' => $description,
        'items' => $items,
    ]);
}
