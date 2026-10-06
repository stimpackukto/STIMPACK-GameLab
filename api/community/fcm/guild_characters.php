<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!function_exists('guild_api_json')) {
    function guild_api_json(bool $success, string $message, array $extra = [], int $code = 200): void
    {
        if (ob_get_length() !== false) {
            ob_clean();
        }

        http_response_code($code);
        echo json_encode(array_merge([
            'success' => $success,
            'ok'      => $success ? 1 : 0,
            'message' => $message,
            'msg'     => $message,
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

set_exception_handler(static function (Throwable $e): void {
    error_log('FCM_GUILD_CHARACTERS_EXCEPTION: ' . $e->getMessage());
    guild_api_json(false, '길드 캐릭터 목록을 불러오지 못했습니다. ' . $e->getMessage(), [
        'debug' => $e->getMessage(),
    ], 500);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();

    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log('FCM_GUILD_CHARACTERS_FATAL: ' . ($err['message'] ?? 'unknown error'));
    guild_api_json(false, '길드 캐릭터 목록 처리 중 오류가 발생했습니다. ' . ($err['message'] ?? 'unknown error'), [
        'debug' => $err['message'] ?? 'unknown error',
    ], 500);
});

require_once __DIR__ . '/fcm_common.php';

if (!defined('FCM_CHARACTERS_DB')) {
    define('FCM_CHARACTERS_DB', 'characters');
}

if (!defined('FCM_COMMUNITY_DB')) {
    define('FCM_COMMUNITY_DB', 'lightguardian_community');
}

if (!function_exists('fcm_ident')) {
    function fcm_ident(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'lightguardian_community';
        return '`' . $name . '`';
    }
}

function guild_api_require_session(mysqli $conn): array
{
    if (function_exists('fcm_require_session')) {
        return fcm_require_session($conn);
    }

    if (function_exists('fcm_require_auth')) {
        return fcm_require_auth($conn);
    }

    $accountId = (int)($_POST['account_id'] ?? $_GET['account_id'] ?? 0);
    $authToken = trim((string)($_POST['auth_token'] ?? $_GET['auth_token'] ?? ''));

    if ($accountId <= 0 || $authToken === '') {
        guild_api_json(false, '로그인이 필요합니다.');
    }

    if (!function_exists('fcm_make_auth_token')) {
        guild_api_json(false, '인증 함수가 없습니다. fcm_common.php를 existing_db 버전으로 교체해야 합니다.');
    }

    $authDb = defined('FCM_AUTH_DB') ? fcm_ident(FCM_AUTH_DB) : '`auth`';
    $stmt = $conn->prepare("
        SELECT id, username, verifier
        FROM {$authDb}.`account`
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        guild_api_json(false, '계정 확인에 실패했습니다. ' . $conn->error);
    }

    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        guild_api_json(false, '계정을 찾지 못했습니다.');
    }

    $username = (string)($row['username'] ?? '');
    $verifier = function_exists('fcm_bin_field')
        ? fcm_bin_field((string)($row['verifier'] ?? ''))
        : (string)($row['verifier'] ?? '');

    if ($username === '' || strlen($verifier) !== 32) {
        guild_api_json(false, '계정 인증 정보가 올바르지 않습니다.');
    }

    $expected = fcm_make_auth_token($accountId, $username, $verifier);

    if (!hash_equals($expected, $authToken)) {
        guild_api_json(false, '로그인이 만료되었습니다.');
    }

    return [
        'account_id' => $accountId,
        'username'   => $username,
        'auth_token' => $authToken,
    ];
}

$conn = fcm_db();
$session = guild_api_require_session($conn);
$accountId = (int)$session['account_id'];
$charDb = fcm_ident(FCM_CHARACTERS_DB);

$sql = "
    SELECT
        c.guid,
        c.name,
        c.level,
        c.`class`,
        gm.guildid AS guild_id,
        g.name AS guild_name
    FROM {$charDb}.`characters` c
    INNER JOIN {$charDb}.`guild_member` gm
        ON gm.guid = c.guid
    INNER JOIN {$charDb}.`guild` g
        ON g.guildid = gm.guildid
    WHERE c.account = ?
    ORDER BY c.level DESC, c.name ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('FCM_GUILD_CHARACTERS_PREP_FAIL: ' . $conn->error);
    fcm_json(false, '길드 캐릭터 목록을 불러오지 못했습니다.');
}

$stmt->bind_param('i', $accountId);
$stmt->execute();
$res = $stmt->get_result();
$characters = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $characters[] = [
            'guid'       => (int)$row['guid'],
            'name'       => (string)$row['name'],
            'level'      => (int)$row['level'],
            'class'      => (int)$row['class'],
            'guild_id'   => (int)$row['guild_id'],
            'guild_name' => (string)$row['guild_name'],
        ];
    }
}

$stmt->close();

fcm_json(true, '길드 캐릭터 목록을 불러왔습니다.', [
    'characters' => $characters,
]);
