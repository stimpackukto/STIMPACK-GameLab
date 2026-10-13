<?php
declare(strict_types=1);

/**
 * GAME LAB Google-authenticated WoW quick registration.
 * Existing WoW /auth/register and its server-only API are not modified.
 * The Google identity is taken exclusively from the verified OAuth session.
 */
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

function quick_reply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function quick_fail(string $message, int $status = 400): never {
    quick_reply($status, ['success' => false, 'message' => $message]);
}
function quick_email_in_use(mysqli $wow, string $email): bool {
    $stmt = $wow->prepare(
        'SELECT id FROM auth.account WHERE LOWER(email) = ? OR LOWER(reg_mail) = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $email, $email);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}
function quick_username_in_use(mysqli $wow, string $username): bool {
    $stmt = $wow->prepare('SELECT id FROM auth.account WHERE UPPER(username) = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}
function quick_srp6_credentials(string $username, string $password): array {
    // Match /wow/includes/login_do.php (TrinityCore WotLK SRP6, little-endian).
    if (!extension_loaded('gmp')) throw new RuntimeException('GMP extension unavailable');
    $salt = random_bytes(32);
    $inner = sha1(strtoupper($username) . ':' . strtoupper($password), true);
    $x = gmp_import(sha1($salt . $inner, true), 1, GMP_LSW_FIRST);
    $n = gmp_init('894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7', 16);
    $verifier = gmp_export(gmp_powm(gmp_init(7), $x, $n), 1, GMP_LSW_FIRST);
    return [$salt, str_pad($verifier, 32, "\0", STR_PAD_RIGHT)];
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    quick_fail('허용되지 않는 요청입니다.', 405);
}

$google = $_SESSION['google_user'] ?? null;
$sub = is_array($google) ? trim((string)($google['sub'] ?? '')) : '';
$email = is_array($google) ? strtolower(trim((string)($google['email'] ?? ''))) : '';
if ($sub === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || empty($_SESSION['google_login_at'])) {
    quick_fail('GAME LAB Google 로그인이 필요합니다.', 401);
}

// Never create a second account while another WoW account is logged in.
$activeWow = $_SESSION['account'] ?? null;
if (is_array($activeWow) && (int)($activeWow['id'] ?? 0) > 0) {
    if ($method === 'GET') quick_reply(200, ['available' => false]);
    quick_fail('먼저 현재 WoW 계정에서 로그아웃해 주세요.', 409);
}

try {
    require_once __DIR__ . '/../includes/db.php';
    $gameLabDb = gamelab_db();

    $wowConfig = dirname(__DIR__) . '/wow/includes/config.php';
    if (!is_file($wowConfig)) throw new RuntimeException('WoW configuration unavailable');
    require_once $wowConfig;
    if (!function_exists('db')) throw new RuntimeException('WoW database factory unavailable');
    $wow = db();
    if (!($wow instanceof mysqli)) throw new RuntimeException('WoW database unavailable');
    $wow->set_charset('utf8mb4');

    // A Google subject is uniquely associated with one WoW account.
    $stmt = $gameLabDb->prepare(
        'SELECT wow_account_id FROM gamelab_wow_google_links WHERE google_sub = ? LIMIT 1'
    );
    $stmt->execute([$sub]);
    $linkedId = (int)($stmt->fetchColumn() ?: 0);
    if ($linkedId > 0) {
        if ($method === 'GET') quick_reply(200, ['available' => false]);
        quick_fail('이미 WoW 계정과 연결된 Google 계정입니다.', 409);
    }

    // A matching email must never be used to take over an existing account.
    if (quick_email_in_use($wow, $email)) {
        if ($method === 'GET') quick_reply(200, ['available' => false]);
        quick_fail('이 Google 이메일로 생성된 WoW 계정이 있습니다. 기존 계정으로 로그인 후 연결해 주세요.', 409);
    }
} catch (Throwable $e) {
    error_log('wow_quick_register: initialization/lookup failed: ' . $e->getMessage());
    quick_fail('계정 정보를 확인하지 못했습니다. 잠시 후 다시 시도해 주세요.', 503);
}

if ($method === 'GET') {
    if (empty($_SESSION['wow_quick_register_csrf'])) {
        $_SESSION['wow_quick_register_csrf'] = bin2hex(random_bytes(32));
    }
    quick_reply(200, [
        'available' => true,
        'email' => $email,
        'csrf' => (string)$_SESSION['wow_quick_register_csrf'],
    ]);
}

// CSRF verification must be server-side (do not trust email/account IDs from POST).
$expected = (string)($_SESSION['wow_quick_register_csrf'] ?? '');
$provided = (string)($_POST['csrf'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    quick_fail('요청이 만료되었습니다. 페이지를 새로고침해 주세요.', 403);
}
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST);
    if (!is_string($host) || strcasecmp($host, (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0
        || parse_url($origin, PHP_URL_SCHEME) !== 'https') {
        quick_fail('허용되지 않은 요청입니다.', 403);
    }
}

// Session-bound rate limit; do not allow unlimited account creation attempts.
$now = time();
$attempts = $_SESSION['wow_quick_register_attempts'] ?? [];
$attempts = is_array($attempts) ? array_values(array_filter(
    $attempts,
    static fn($t): bool => is_int($t) && $t > $now - 3600
)) : [];
if (count($attempts) >= 6) quick_fail('요청 횟수가 많습니다. 나중에 다시 시도해 주세요.', 429);
$attempts[] = $now;
$_SESSION['wow_quick_register_attempts'] = $attempts;

$username = strtoupper(trim((string)($_POST['username'] ?? '')));
$password = (string)($_POST['password'] ?? '');
if (!preg_match('/^[A-Z0-9_]{3,32}$/D', $username)) {
    quick_fail('아이디는 영문, 숫자, 밑줄로 3~32자 입력해 주세요.');
}
if (strlen($password) < 8 || strlen($password) > 72
    || !preg_match('/[^A-Za-z0-9]/', $password)) {
    quick_fail('비밀번호는 8~72자이며 특수문자를 포함해야 합니다.');
}

try {
    if (quick_username_in_use($wow, $username)) {
        quick_fail('이미 사용 중인 아이디입니다.', 409);
    }
    [$salt, $verifier] = quick_srp6_credentials($username, $password);

    // Create a real TrinityCore account, not just a website-only user.
    // expansion=2 enables the WotLK expansion for new accounts.
    $wow->begin_transaction();
    try {
        $stmt = $wow->prepare(
            'INSERT INTO auth.account (username, salt, verifier, email, reg_mail, joindate, expansion) VALUES (?, ?, ?, ?, ?, NOW(), 2)'
        );
        $stmt->bind_param('sssss', $username, $salt, $verifier, $email, $email);
        $stmt->execute();
        $wowId = (int)$wow->insert_id;
        $stmt->close();

        // Keep existing point/RP screens usable when this row is initialized separately.
        // A custom appdata schema may not support a minimal insert; never grant points.
        try {
            $accountData = $wow->prepare(
                'INSERT INTO appdata.account_data (id, point, reg_point) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE id = id'
            );
            $accountData->bind_param('i', $wowId);
            $accountData->execute();
            $accountData->close();
        } catch (Throwable $extra) {
            error_log('wow_quick_register: account_data initialization skipped: ' . $extra->getMessage());
        }

        $wow->commit();
    } catch (Throwable $insertError) {
        $wow->rollback();
        throw $insertError;
    }

    try {
        // Unique constraints on google_sub and wow_account_id enforce 1:1 linking.
        $stmt = $gameLabDb->prepare(
            'INSERT INTO gamelab_wow_google_links (google_sub, wow_account_id, google_email, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$sub, $wowId, $email]);
    } catch (Throwable $linkError) {
        error_log('wow_quick_register: account created but auto-link failed for account ' . $wowId . ': ' . $linkError->getMessage());
        unset($_SESSION['wow_quick_register_csrf']);
        quick_reply(200, [
            'success' => true,
            'linked' => false,
            'message' => 'WoW 계정은 생성됐지만 Google 자동 연동에 실패했습니다. 입력한 아이디와 비밀번호로 WoW 로그인 후 계정을 연결해 주세요.',
            'redirect' => '/wow/auth/login',
        ]);
    }

    unset($_SESSION['wow_quick_register_csrf']);
    // Reuse the existing, well-tested Google -> WoW session bridge.
    $autoLogin = false;
    try {
        require_once __DIR__ . '/wow_session_bridge.php';
        $autoLogin = gamelab_wow_session_login();
    } catch (Throwable $loginError) {
        error_log('wow_quick_register: auto login failed: ' . $loginError->getMessage());
    }
    quick_reply(200, [
        'success' => true,
        'linked' => true,
        'message' => $autoLogin ? 'WoW 계정이 생성되고 자동 연결되었습니다.' : '계정이 생성되고 Google과 연결되었습니다. 다시 Google 로그인하면 자동 로그인됩니다.',
        'redirect' => $autoLogin ? '/wow/' : '/auth/google_login.php',
    ]);
} catch (Throwable $e) {
    error_log('wow_quick_register: create failed: ' . $e->getMessage());
    // Database uniqueness errors also protect against concurrent duplicate usernames.
    if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
        quick_fail('이미 사용 중인 아이디입니다.', 409);
    }
    quick_fail('WoW 계정 생성에 실패했습니다. 다시 확인해 주세요.', 503);
}
