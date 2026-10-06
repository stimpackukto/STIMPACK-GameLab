<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog.php';


/* =========================================================
 * 기본 웹게임
 * ========================================================= */

$defaultGames = [];


/* 관리자가 추가한 게임을 위에 표시 */

$customGames = catalog_load('game');

$games = array_merge(
    array_reverse($customGames),
    $defaultGames
);


/* =========================================================
 * 기본 앱
 * ========================================================= */

$defaultApps = [];

$customApps = catalog_load('app');

$apps = array_merge(
    array_reverse($customApps),
    $defaultApps
);


/* =========================================================
 * 개발일지
 * ========================================================= */

$devlogs = [
    ['date' => '2026.09.27', 'tag' => '포털', 'title' => 'GAME LAB 포털 구조 설계 시작'],
    ['date' => '2026.09.27', 'tag' => '크라임씬', 'title' => '웹게임 모바일 입력 구조 개선'],
    ['date' => '2026.09.27', 'tag' => '앱', 'title' => '진료기록노트 음성 입력 기능 설계'],
    ['date' => '2026.09.26', 'tag' => '게임', 'title' => '클래식 보드게임 프로젝트 정리'],
];


$communityPosts = [
    ['category' => '웹게임', 'title' => 'Godot 웹게임 모바일 입력 문제 정리', 'meta' => '토론 준비중'],
    ['category' => 'Android', 'title' => '개인 개발 앱 광고와 배포 이야기', 'meta' => '토론 준비중'],
    ['category' => '서버', 'title' => 'Nginx + PHP 게임 포털 운영 구조', 'meta' => '토론 준비중'],
];