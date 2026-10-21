<?php
declare(strict_types=1);

// Session-only authentication bridge. No additional Janggi user table is created.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Vary: Cookie');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET'); http_response_code(405);
    echo json_encode(['ok'=>false,'authenticated'=>false,'message'=>'GET만 허용됩니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
// Match the existing GAME LAB Google OAuth PHP-session cookie properties.
session_set_cookie_params([
    'lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$user = $_SESSION['google_user'] ?? null;
if (!is_array($user) || !is_string($user['sub'] ?? null) || trim($user['sub']) === '') {
    echo json_encode(['ok'=>true,'authenticated'=>false,'user'=>null], JSON_UNESCAPED_UNICODE);
    exit;
}
// Stable pseudonymous ID used for storing ONLY opted-in records. Must be configured
// privately in PHP-FPM / server environment; do not put this secret in GitHub.
$secret=(string)(getenv('STIMPACK_JANGGI_ACCOUNT_HMAC_SECRET') ?: '');
if (strlen($secret)<32) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'authenticated'=>false,
       'message'=>'장기 계정 연결이 아직 서버에 설정되지 않았습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$accountId=hash_hmac('sha256',trim($user['sub']),$secret);
echo json_encode(['ok'=>true,'authenticated'=>true,
    'user'=>[
        'id'=>$accountId,
        'name'=>mb_substr((string)($user['name']??''),0,120),
        'email'=>mb_substr((string)($user['email']??''),0,180),
        'picture'=>mb_substr((string)($user['picture']??''),0,512)
    ]
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
