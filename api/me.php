<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

echo json_encode([
    'ok' => true,
    'authenticated' => false,
    'user' => null,
    'message' => 'STIMPACK GAME LAB 공통 로그인 API 자리입니다. Google Auth 연결 전에는 게스트로 응답합니다.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
