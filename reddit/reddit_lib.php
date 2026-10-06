<?php
declare(strict_types=1);

function reddit_cfg(?string $key = null, mixed $default = null): mixed
{
    global $REDDIT_CONFIG;
    if ($key === null) return $REDDIT_CONFIG;
    return $REDDIT_CONFIG[$key] ?? $default;
}

function reddit_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reddit_is_configured(): bool
{
    $id = trim((string)reddit_cfg('client_id', ''));
    $secret = trim((string)reddit_cfg('client_secret', ''));
    $redirect = trim((string)reddit_cfg('redirect_uri', ''));
    return $id !== '' && $secret !== '' && $redirect !== ''
        && !str_contains($id, 'CHANGE_ME')
        && !str_contains($secret, 'CHANGE_ME');
}

function reddit_token_store_path(): string
{
    return (string)reddit_cfg('token_store', '/var/lib/stimpack/reddit/token.json');
}

function reddit_read_token_store(): array
{
    $path = reddit_token_store_path();
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function reddit_write_token_store(array $data): void
{
    $path = reddit_token_store_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create Reddit token directory.');
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write Reddit token store.');
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot replace Reddit token store.');
    }
}

function reddit_user_agent(): string
{
    $ua = trim((string)reddit_cfg('user_agent', ''));
    if ($ua === '') {
        $ua = 'web:forum.pe.kr:1.0 (by /u/unknown)';
    }
    return $ua;
}

/**
 * @return array{status:int,body:string,json:?array,headers:string}
 */
function reddit_http(string $method, string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }

    $ch = curl_init($url);
    $headers = $options['headers'] ?? [];
    $headers[] = 'Accept: application/json';

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_USERAGENT => reddit_user_agent(),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if (isset($options['basic_auth'])) {
        [$user, $pass] = $options['basic_auth'];
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    }

    if (array_key_exists('form', $options)) {
        $form = http_build_query($options['form'], '', '&', PHP_QUERY_RFC3986);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Reddit network error: ' . $error);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr((string)$raw, 0, $headerSize);
    $body = substr((string)$raw, $headerSize);
    $json = json_decode($body, true);

    return [
        'status' => $status,
        'body' => $body,
        'json' => is_array($json) ? $json : null,
        'headers' => $headerText,
    ];
}

function reddit_client_credentials(): array
{
    if (!reddit_is_configured()) {
        throw new RuntimeException('Reddit client_id/client_secret/redirect_uri are not configured.');
    }
    return [(string)reddit_cfg('client_id'), (string)reddit_cfg('client_secret')];
}

function reddit_exchange_code(string $code): array
{
    [$id, $secret] = reddit_client_credentials();
    $res = reddit_http('POST', 'https://www.reddit.com/api/v1/access_token', [
        'basic_auth' => [$id, $secret],
        'form' => [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string)reddit_cfg('redirect_uri'),
        ],
    ]);

    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
        throw new RuntimeException('Reddit token exchange failed (HTTP ' . $res['status'] . ').');
    }
    if (!empty($res['json']['error'])) {
        throw new RuntimeException('Reddit token error: ' . (string)$res['json']['error']);
    }
    if (empty($res['json']['access_token'])) {
        throw new RuntimeException('Reddit did not return an access token.');
    }
    return $res['json'];
}

function reddit_refresh_access_token(string $refreshToken): array
{
    [$id, $secret] = reddit_client_credentials();
    $res = reddit_http('POST', 'https://www.reddit.com/api/v1/access_token', [
        'basic_auth' => [$id, $secret],
        'form' => [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ],
    ]);

    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
        throw new RuntimeException('Reddit token refresh failed (HTTP ' . $res['status'] . ').');
    }
    if (!empty($res['json']['error'])) {
        throw new RuntimeException('Reddit refresh error: ' . (string)$res['json']['error']);
    }
    if (empty($res['json']['access_token'])) {
        throw new RuntimeException('Reddit did not return a refreshed access token.');
    }
    return $res['json'];
}

function reddit_get_me(string $accessToken): array
{
    $res = reddit_http('GET', 'https://oauth.reddit.com/api/v1/me', [
        'headers' => ['Authorization: Bearer ' . $accessToken],
    ]);
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
        throw new RuntimeException('Reddit identity check failed (HTTP ' . $res['status'] . ').');
    }
    return $res['json'];
}

function reddit_access_token(): string
{
    $path = reddit_token_store_path();
    $lockPath = $path . '.lock';
    $dir = dirname($lockPath);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create Reddit state directory.');
    }

    $fp = @fopen($lockPath, 'c+');
    if (!$fp) throw new RuntimeException('Cannot open Reddit token lock.');

    try {
        if (!flock($fp, LOCK_EX)) throw new RuntimeException('Cannot lock Reddit token store.');
        $store = reddit_read_token_store();
        $access = (string)($store['access_token'] ?? '');
        $expiresAt = (int)($store['expires_at'] ?? 0);
        if ($access !== '' && $expiresAt > time() + 90) {
            return $access;
        }

        $refresh = (string)($store['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new RuntimeException('Reddit is not authorized yet. Open /reddit/oauth_start.php as an admin.');
        }

        $token = reddit_refresh_access_token($refresh);
        $store['access_token'] = (string)$token['access_token'];
        $store['expires_at'] = time() + max(60, (int)($token['expires_in'] ?? 3600));
        $store['scope'] = (string)($token['scope'] ?? ($store['scope'] ?? ''));
        $store['updated_at'] = date(DATE_ATOM);
        reddit_write_token_store($store);
        return $store['access_token'];
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

function reddit_authorize_url(): string
{
    $state = bin2hex(random_bytes(24));
    $_SESSION['reddit_oauth_state'] = $state;
    $_SESSION['reddit_oauth_state_time'] = time();

    $query = http_build_query([
        'client_id' => (string)reddit_cfg('client_id'),
        'response_type' => 'code',
        'state' => $state,
        'redirect_uri' => (string)reddit_cfg('redirect_uri'),
        'duration' => 'permanent',
        'scope' => 'identity submit',
    ], '', '&', PHP_QUERY_RFC3986);

    return 'https://www.reddit.com/api/v1/authorize?' . $query;
}

function reddit_validate_oauth_state(string $state): bool
{
    $stored = (string)($_SESSION['reddit_oauth_state'] ?? '');
    $when = (int)($_SESSION['reddit_oauth_state_time'] ?? 0);
    unset($_SESSION['reddit_oauth_state'], $_SESSION['reddit_oauth_state_time']);
    return $stored !== '' && hash_equals($stored, $state) && $when >= time() - 900;
}

function reddit_current_account_id(): int
{
    return (int)(defined('ACCOUNT_ID') ? ACCOUNT_ID : 0);
}

function reddit_is_admin(): bool
{
    $uid = reddit_current_account_id();
    if ($uid <= 0) return false;
    $ids = array_map('intval', (array)reddit_cfg('admin_account_ids', []));
    return in_array($uid, $ids, true);
}

function reddit_require_admin(): void
{
    if (!reddit_is_admin()) {
        $uid = reddit_current_account_id();
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>Reddit 관리자 인증</title>';
        echo '<style>body{font-family:system-ui;background:#0b1220;color:#e8eef8;padding:30px;line-height:1.7}code{color:#ffd36a}</style>';
        echo '<h2>Reddit 관리자 전용</h2>';
        if ($uid > 0) {
            echo '<p>현재 로그인 ACCOUNT_ID: <code>' . htmlspecialchars((string)$uid, ENT_QUOTES, 'UTF-8') . '</code></p>';
            echo '<p><code>/etc/stimpack/reddit.php</code>의 <code>admin_account_ids</code>에 이 번호를 추가하세요.</p>';
        } else {
            echo '<p>먼저 forum.pe.kr에 로그인하세요.</p>';
        }
        exit;
    }
}

function reddit_csrf_token(): string
{
    if (empty($_SESSION['reddit_csrf']) || !is_string($_SESSION['reddit_csrf'])) {
        $_SESSION['reddit_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['reddit_csrf'];
}

function reddit_verify_csrf(string $token): bool
{
    $stored = (string)($_SESSION['reddit_csrf'] ?? '');
    return $stored !== '' && $token !== '' && hash_equals($stored, $token);
}

function reddit_origin_allowed(): bool
{
    $allowed = array_values(array_filter(array_map('strval', (array)reddit_cfg('allowed_origins', ['https://forum.pe.kr']))));
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') return in_array($origin, $allowed, true);

    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer === '') return true; // CLI/curl or privacy settings; CSRF still required.
    foreach ($allowed as $base) {
        if (str_starts_with($referer, rtrim($base, '/') . '/')) return true;
    }
    return false;
}

function reddit_source_config(string $source): array
{
    $sources = (array)reddit_cfg('sources', []);
    $cfg = $sources[$source] ?? null;
    if (!is_array($cfg)) throw new InvalidArgumentException('허용되지 않은 게시 경로입니다.');
    return $cfg;
}

function reddit_rate_limit(string $source, int $uid, string $ip): void
{
    $seconds = max(5, (int)reddit_cfg('rate_limit_seconds', 60));
    $base = (string)reddit_cfg('rate_store_dir', '/var/lib/stimpack/reddit/rate');
    if (!is_dir($base)) @mkdir($base, 0750, true);
    $identity = $uid > 0 ? 'uid:' . $uid : 'ip:' . $ip;
    $file = rtrim($base, '/') . '/' . hash('sha256', $source . '|' . $identity) . '.txt';
    $last = is_file($file) ? (int)@file_get_contents($file) : 0;
    $remain = ($last + $seconds) - time();
    if ($remain > 0) throw new RuntimeException('연속 등록 방지를 위해 ' . $remain . '초 후 다시 시도하세요.');
    @file_put_contents($file, (string)time(), LOCK_EX);
}

function reddit_submit_text(string $source, string $title, string $body): array
{
    $route = reddit_source_config($source);
    $subreddit = trim((string)($route['subreddit'] ?? ''));
    $prefix = (string)($route['title_prefix'] ?? '');
    if ($subreddit === '') throw new RuntimeException('Subreddit is not configured.');

    $fullTitle = trim($prefix . trim($title));
    if ($fullTitle === '' || mb_strlen($fullTitle, 'UTF-8') > 300) {
        throw new InvalidArgumentException('제목은 머릿글 포함 300자 이하여야 합니다.');
    }
    if (mb_strlen($body, 'UTF-8') > 40000) {
        throw new InvalidArgumentException('본문은 40,000자 이하여야 합니다.');
    }

    $token = reddit_access_token();
    $res = reddit_http('POST', 'https://oauth.reddit.com/api/submit', [
        'headers' => ['Authorization: Bearer ' . $token],
        'form' => [
            'api_type' => 'json',
            'kind' => 'self',
            'sr' => $subreddit,
            'title' => $fullTitle,
            'text' => $body,
            'resubmit' => 'true',
            'sendreplies' => 'true',
            'raw_json' => '1',
        ],
    ]);

    if ($res['status'] === 401) {
        // Force a refresh once if Reddit invalidated the cached access token.
        $store = reddit_read_token_store();
        $store['access_token'] = '';
        $store['expires_at'] = 0;
        reddit_write_token_store($store);
        $token = reddit_access_token();
        $res = reddit_http('POST', 'https://oauth.reddit.com/api/submit', [
            'headers' => ['Authorization: Bearer ' . $token],
            'form' => [
                'api_type' => 'json', 'kind' => 'self', 'sr' => $subreddit,
                'title' => $fullTitle, 'text' => $body, 'resubmit' => 'true',
                'sendreplies' => 'true', 'raw_json' => '1',
            ],
        ]);
    }

    $json = $res['json'];
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($json)) {
        throw new RuntimeException('Reddit 게시 실패 (HTTP ' . $res['status'] . ').');
    }

    $errors = $json['json']['errors'] ?? [];
    if (is_array($errors) && count($errors) > 0) {
        $messages = [];
        foreach ($errors as $e) {
            if (is_array($e)) $messages[] = (string)($e[1] ?? $e[0] ?? 'Reddit error');
        }
        throw new RuntimeException('Reddit: ' . implode(' / ', array_filter($messages)));
    }

    $data = is_array($json['json']['data'] ?? null) ? $json['json']['data'] : [];
    $url = (string)($data['url'] ?? '');
    $name = (string)($data['name'] ?? '');
    $id = (string)($data['id'] ?? '');

    if ($url === '' && $id !== '') {
        $url = 'https://www.reddit.com/r/' . rawurlencode($subreddit) . '/comments/' . rawurlencode($id) . '/';
    }

    return [
        'subreddit' => $subreddit,
        'title' => $fullTitle,
        'url' => $url,
        'name' => $name,
        'id' => $id,
    ];
}

function reddit_log_post(array $row): void
{
    $path = (string)reddit_cfg('post_log', '/var/lib/stimpack/reddit/post.log');
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
