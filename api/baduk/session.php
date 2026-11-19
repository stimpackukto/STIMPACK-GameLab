<?php
declare(strict_types=1);
// STIMPACK Baduk — bridge an existing verified GameLab Google PHP session
// into a 5-minute HMAC-signed bearer ticket for the local Node WebSocket.
// This endpoint does not perform OAuth and never exposes the signing key.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Vary: Cookie');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET'); http_response_code(405);
    echo '{"ok":false,"error":"method_not_allowed"}'; exit;
}
if (strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) !== 'stimpack.pe.kr') {
    http_response_code(403);echo '{"ok":false,"error":"invalid_host"}';exit;
}
session_start();
$user = $_SESSION['google_user'] ?? null;
if (!is_array($user) || !preg_match('/^[0-9]{8,32}$/D', (string)($user['sub'] ?? ''))
    || !filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
    http_response_code(401);
    echo '{"ok":false,"error":"google_login_required"}'; exit;
}
$secret = getenv('BADUK_AUTH_SECRET');
if (!is_string($secret) || strlen(trim($secret)) < 32) {
    $secret = @file_get_contents('/etc/stimpack/baduk_auth_secret');
}
$secret = is_string($secret) ? trim($secret) : '';
if (strlen($secret) < 32) {
    http_response_code(503);
    echo '{"ok":false,"error":"baduk_auth_not_configured"}'; exit;
}
$now = time();
$name = trim((string)($user['name'] ?? ''));
if ($name === '') $name = '대국자';
$name = mb_substr($name, 0, 60);
$payload = json_encode([
    'v'=>1, 'sub'=>(string)$user['sub'], 'name'=>$name,
    'iat'=>$now, 'exp'=>$now + 300,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$body = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $body, $secret);
session_write_close();
echo json_encode([
    'ok'=>true, 'ticket'=>$body.'.'.$signature, 'expiresAt'=>$now + 300,
    'user'=>['name'=>$name],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
