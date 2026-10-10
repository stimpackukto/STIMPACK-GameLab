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

function wow_link_manage_page(string $email, string $name, string $token, bool $recent): void {
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GAME LAB 계정 연결 관리</title></head><body style="margin:0;padding:30px;background:#061421;color:#e4f5ff;font-family:system-ui,sans-serif"><main style="max-width:540px;margin:30px auto;padding:24px;border-radius:14px;background:#0b2335;border:1px solid #24506b"><h2>GAME LAB 계정 연결 완료</h2>';
    echo '<p>Google: <strong>'.wow_link_h($email).'</strong><br>WoW: <strong>'.wow_link_h($name).'</strong></p>';
    if ($recent) {
        echo '<form method="post" action="/auth/wow_link.php"><input type="hidden" name="csrf" value="'.wow_link_h($token).'"><button name="action" value="unlink" type="submit" style="padding:10px 16px;border:1px solid #ec8c92;background:#431d29;color:#fff;border-radius:8px;cursor:pointer">Google 계정 연결 해제</button></form>';
    } else {
        echo '<p>연결 해제하려면 WoW에서 다시 로그인한 뒤 15분 안에 이 페이지로 돌아와 주세요.</p>';
    }
    echo '<p><a style="color:#90d9fc" href="/wow/account/my">WoW 마이페이지</a></p></main></body></html>';
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
// Existing link status can be viewed without requiring a recent password login.
// Changes (link or unlink) require recent WoW login and CSRF verification.
$recentWoWLogin = $loginAt > 0 && $loginAt <= time() + 60 && time() - $loginAt <= 900;
try {
    $stmt = gamelab_db()->prepare('SELECT google_sub, wow_account_id, google_email FROM gamelab_wow_google_links WHERE google_sub = ? OR wow_account_id = ?');
    $stmt->execute([$sub, $wowId]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('wow_link: lookup error '.$e->getCode());
    http_response_code(503);
    wow_link_page('연동 상태를 조회하지 못했습니다. 잠시 후 다시 시도해 주세요.', 'error');
}
$linked = false;
foreach ($matches as $match) {
    if ((string)$match['google_sub'] !== $sub || (int)$match['wow_account_id'] !== $wowId) {
        http_response_code(409);
        wow_link_page('현재 Google 또는 WoW 계정이 다른 계정에 연결되어 있습니다. 계정 소유권을 확인해 주세요.', 'error');
    }
    $linked = true;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $linked) {
    $_SESSION['wow_link_csrf'] = bin2hex(random_bytes(32));
    wow_link_manage_page($email, $wowName, $_SESSION['wow_link_csrf'], $recentWoWLogin);
}
if (!$recentWoWLogin) {
    wow_link_page('보안을 위해 WoW 계정에 다시 로그인한 뒤 15분 이내에 연동 또는 해제해 주세요.', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string)($_POST['csrf'] ?? '');
    $stored = (string)($_SESSION['wow_link_csrf'] ?? '');
    unset($_SESSION['wow_link_csrf']);
    if ($posted === '' || $stored === '' || !hash_equals($stored, $posted)) {
        http_response_code(403);
        wow_link_page('유효하지 않은 연동 요청입니다. 다시 시도해 주세요.', 'error');
    }
    if (($_POST['action'] ?? '') === 'unlink') {
        if (!$linked) {
            http_response_code(409);
            wow_link_page('연결된 계정이 없습니다.', 'error');
        }
        try {
            $stmt = gamelab_db()->prepare('DELETE FROM gamelab_wow_google_links WHERE google_sub = ? AND wow_account_id = ?');
            $stmt->execute([$sub, $wowId]);
            wow_link_page('Google 계정 연결이 해제됐습니다. WoW 계정은 그대로 유지됩니다.');
        } catch (PDOException $e) {
            error_log('wow_link: unlink error '.$e->getCode());
            http_response_code(503);
            wow_link_page('연결 해제에 실패했습니다.', 'error');
        }
    }
    if (($_POST['action'] ?? '') !== 'link') {
        http_response_code(403);
        wow_link_page('허용되지 않은 요청입니다.', 'error');
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
        wow_link_page('계정 연결이 완료됐습니다. 다음 Google 로그인부터 WoW 웹 자동 로그인이 적용됩니다.');
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
