<?php
declare(strict_types=1);

// Same-origin bridge: account ownership must be proven by BOTH existing logins.
// No Google credentials, WoW passwords or OAuth tokens are stored.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../includes/db.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'');

function wow_link_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function wow_link_page(string $message, string $tone = 'info', bool $confirm = false, string $email = '', string $name = '', string $token = ''): void {
    $accent = $tone === 'error' ? '#ff9aa4' : '#8bdcf9';
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GAME LAB · WoW 계정 연동</title></head>';
    echo '<body style="margin:0;background:#061421;color:#e4f5ff;font-family:system-ui,sans-serif;padding:30px 15px"><main style="max-width:520px;margin:45px auto;border:1px solid #24506b;background:#0b2335;border-radius:16px;padding:26px">';
    echo '<h2 style="margin:0 0 16px">STIMPACK GAME LAB 계정 연동</h2><p style="line-height:1.65;color:'. $accent .'">'.wow_link_h($message).'</p>';
    if ($confirm) {
        echo '<p>WoW 계정: <strong>'.wow_link_h($name).'</strong><br>Google 계정: <strong>'.wow_link_h($email).'</strong></p>';
        echo '<form method="post" action="/auth/wow_link.php"><input type="hidden" name="csrf" value="'.wow_link_h($token).'">';
        echo '<button type="submit" name="action" value="link" style="border:0;border-radius:8px;padding:12px 20px;background:#168fd0;color:white;font-weight:bold;cursor:pointer">두 계정 연결 확인</button></form>';
    }
    echo '<p style="margin-top:22px;font-size:13px"><a style="color:#90d9fc" href="/wow/account/my">WoW 마이페이지</a> · <a style="color:#90d9fc" href="/">GAME LAB 홈</a></p></main></body></html>';
    exit;
}

$account = $_SESSION['account'] ?? null;
$google = $_SESSION['google_user'] ?? null;
$wowId = is_array($account) ? (int)($account['id'] ?? 0) : 0;
$wowName = is_array($account) ? trim((string)($account['username'] ?? '')) : '';
$loginAt = is_array($account) ? (int)($account['login_at'] ?? 0) : 0;
$sub = is_array($google) ? trim((string)($google['sub'] ?? '')) : '';
$email = is_array($google) ? trim((string)($google['email'] ?? '')) : '';

if ($wowId <= 0 || $wowName === '') {
    wow_link_page('먼저 WoW에 로그인해야 합니다. WoW 마이페이지에서 다시 시작해 주세요.', 'error');
}
if ($sub === '' || $email === '') {
    wow_link_page('GAME LAB 구글 로그인이 필요합니다. GAME LAB에서 구글 로그인을 완료한 후 WoW 마이페이지의 연동 버튼을 다시 눌러 주세요.', 'error');
}
// Linking requires recent proof of possession of the WoW account.
if ($loginAt <= 0 || time() - $loginAt > 900 || $loginAt > time() + 60) {
    wow_link_page('보안을 위해 WoW 계정에 다시 로그인한 뒤 15분 이내에 연동해 주세요.', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string)($_POST['csrf'] ?? '');
    $stored = (string)($_SESSION['wow_link_csrf'] ?? '');
    unset($_SESSION['wow_link_csrf']);
    if ($posted === '' || $stored === '' || !hash_equals($stored, $posted) || ($_POST['action'] ?? '') !== 'link') {
        http_response_code(403);
        wow_link_page('유효하지 않은 연동 요청입니다. 다시 시도해 주세요.', 'error');
    }
    try {
        $db = gamelab_db();
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT google_sub, wow_account_id FROM gamelab_wow_google_links WHERE google_sub = ? OR wow_account_id = ? FOR UPDATE');
        $stmt->execute([$sub, $wowId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['google_sub'] !== $sub || (int)$row['wow_account_id'] !== $wowId) {
                $db->rollBack();
                http_response_code(409);
                wow_link_page('이 구글 계정 또는 WoW 계정은 이미 다른 계정에 연결되어 있습니다.', 'error');
            }
            $db->commit();
            wow_link_page('이미 연결된 계정입니다. 추가 변경은 없습니다.');
        }
        $stmt = $db->prepare('INSERT INTO gamelab_wow_google_links (google_sub, wow_account_id, google_email, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$sub, $wowId, $email]);
        $db->commit();
        wow_link_page('계정 연결이 완료됐습니다. 자동 로그인은 별도 검증 후 활성화됩니다.');
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log('wow_link: database error '.$e->getCode());
        http_response_code(503);
        wow_link_page('연동 저장소를 사용할 수 없습니다. 테이블 설치 여부를 확인해 주세요.', 'error');
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    wow_link_page('허용되지 않는 요청입니다.', 'error');
}
$_SESSION['wow_link_csrf'] = bin2hex(random_bytes(32));
wow_link_page('아래 두 계정이 본인 소유인지 확인한 후 연결해 주세요.', 'info', true, $email, $wowName, $_SESSION['wow_link_csrf']);
