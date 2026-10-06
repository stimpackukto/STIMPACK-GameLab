<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Referrer-Policy: no-referrer');
reddit_require_admin();

function finish_page(string $title, string $message, bool $ok): never {
    http_response_code($ok ? 200 : 400);
    $c = $ok ? '#6fe39a' : '#ff8f8f';
    echo '<!doctype html><html lang="ko"><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>body{font-family:system-ui;background:#0b1220;color:#e8eef8;padding:32px;line-height:1.7}a{color:#79b8ff}.box{max-width:700px;margin:auto;border:1px solid #263552;border-radius:14px;padding:24px;background:#0e1630}h2{color:' . $c . '}</style>';
    echo '<div class="box"><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/wow/">GAME LAB으로 돌아가기</a></p></div></html>';
    exit;
}

try {
    if (!empty($_GET['error'])) {
        finish_page('Reddit 인증 취소', (string)$_GET['error'], false);
    }
    $state = (string)($_GET['state'] ?? '');
    $code  = (string)($_GET['code'] ?? '');
    if ($state === '' || $code === '' || !reddit_validate_oauth_state($state)) {
        finish_page('Reddit 인증 실패', 'OAuth state 또는 code가 올바르지 않습니다.', false);
    }

    $token = reddit_exchange_code($code);
    $refresh = (string)($token['refresh_token'] ?? '');
    if ($refresh === '') {
        finish_page('Reddit 인증 실패', 'refresh_token을 받지 못했습니다. duration=permanent 승인을 다시 진행하세요.', false);
    }

    $access = (string)$token['access_token'];
    $me = reddit_get_me($access);
    $username = (string)($me['name'] ?? '');
    $expected = trim((string)reddit_cfg('expected_reddit_username', ''));
    if ($expected !== '' && strcasecmp($expected, $username) !== 0) {
        finish_page('Reddit 계정 불일치', '승인된 계정 u/' . $username . ' 이 설정된 u/' . $expected . ' 과 다릅니다.', false);
    }

    reddit_write_token_store([
        'refresh_token' => $refresh,
        'access_token' => $access,
        'expires_at' => time() + max(60, (int)($token['expires_in'] ?? 3600)),
        'scope' => (string)($token['scope'] ?? ''),
        'username' => $username,
        'authorized_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
    ]);

    finish_page('Reddit 인증 완료', 'u/' . $username . ' 계정이 forum.pe.kr 공통 Reddit 인증에 연결되었습니다.', true);
} catch (Throwable $e) {
    finish_page('Reddit 인증 실패', $e->getMessage(), false);
}
