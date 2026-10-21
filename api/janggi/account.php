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
// Server-side pseudonymous ID. No user profile table or secret in web root.
require_once dirname(__DIR__, 2) . '/includes/janggi_account_key.php';
$accountId=janggi_pseudonymous_account_id((string)$user['sub']);
if ($accountId===null) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'authenticated'=>false,
       'message'=>'장기 서버 비밀키 설정이 필요합니다. 관리자에게 문의해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['ok'=>true,'authenticated'=>true,
    'user'=>[
        'id'=>$accountId,
        'name'=>mb_substr((string)($user['name']??''),0,120),
        'email'=>mb_substr((string)($user['email']??''),0,180),
        'picture'=>mb_substr((string)($user['picture']??''),0,512)
    ]
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
