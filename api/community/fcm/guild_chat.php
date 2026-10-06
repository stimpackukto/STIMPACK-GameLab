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
    error_log('FCM_GUILD_CHAT_EXCEPTION: ' . $e->getMessage());
    guild_api_json(false, '길드 채팅 처리 중 오류가 발생했습니다. ' . $e->getMessage(), [
        'debug' => $e->getMessage(),
    ], 500);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();

    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log('FCM_GUILD_CHAT_FATAL: ' . ($err['message'] ?? 'unknown error'));
    guild_api_json(false, '길드 채팅 처리 중 오류가 발생했습니다. ' . ($err['message'] ?? 'unknown error'), [
        'debug' => $err['message'] ?? 'unknown error',
    ], 500);
});

require_once __DIR__ . '/fcm_common.php';
require_once __DIR__ . '/fcm_soap_common.php';

if (!defined('FCM_CHARACTERS_DB')) {
    define('FCM_CHARACTERS_DB', 'characters');
}

if (!defined('FCM_APPDATA_DB')) {
    define('FCM_APPDATA_DB', 'appdata');
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
$appDb = fcm_ident(FCM_APPDATA_DB);
$communityDb = fcm_ident(FCM_COMMUNITY_DB);

function guild_chat_clean_text(string $text): string
{
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
    $text = trim($text);
    $text = str_replace('"', "'", $text);

    return $text;
}

function guild_chat_message_for_app(string $text): string
{
    $text = preg_replace_callback(
        '/\|c[0-9A-Fa-f]{6,8}\|H[^|]*\|h(\[[^\]]+\])\|h\|r/u',
        static function (array $m): string {
            return $m[1];
        },
        $text
    ) ?? $text;

    return preg_replace_callback(
        '/\|c[0-9A-Fa-f]{6,8}(\[[^\]]+\])\|r/u',
        static function (array $m): string {
            return $m[1];
        },
        $text
    ) ?? $text;
}

function guild_chat_execute_soap_command(string $command): array
{
    if (function_exists('fcm_execute_soap_command')) {
        $settings = function_exists('fcm_soap_settings') ? fcm_soap_settings() : ['port' => 7878];
        $port = (int)($settings['port'] ?? 7878);
        $res = fcm_execute_soap_command($command, $port);
    } elseif (function_exists('ExecuteSoapCommand')) {
        $settings = function_exists('fcm_soap_settings') ? fcm_soap_settings() : ['port' => 7878];
        $port = (int)($settings['port'] ?? 7878);
        $res = ExecuteSoapCommand($command, $port);
    } else {
        return [
            'ok' => false,
            'message' => 'ExecuteSoapCommand 함수가 없습니다.',
        ];
    }

    if (!is_array($res)) {
        return [
            'ok' => false,
            'message' => 'SOAP 응답 형식이 올바르지 않습니다.',
            'raw' => $res,
        ];
    }

    return [
        'ok' => !empty($res['sent']) || !empty($res['ok']),
        'message' => (string)($res['message'] ?? ''),
        'raw' => $res,
    ];
}

function guild_chat_send_soap(int $guildId, string $characterName, string $message): array
{
    $guildId = (int)$guildId;
    $characterName = guild_chat_clean_text($characterName);
    $message = guild_chat_clean_text($message);

    if ($guildId <= 0 || $characterName === '' || $message === '') {
        return [
            'ok' => false,
            'message' => 'SOAP 명령어 인자 오류',
        ];
    }

    $soapMessage = '|cffffcc00[A]|r' . $message;
    $command = '.send guildsay ' . $guildId . ' ' . $characterName . ' "' . $soapMessage . '"';

    try {
        $res = guild_chat_execute_soap_command($command);

        return [
            'ok' => !empty($res['ok']),
            'message' => (string)($res['message'] ?? ''),
            'command' => $command,
            'raw' => $res,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => $e->getMessage(),
            'command' => $command,
        ];
    }
}

function get_guild_character(mysqli $conn, string $charDb, int $accountId, int $guildId, int $charGuid): ?array
{
    if ($accountId <= 0 || $guildId <= 0 || $charGuid <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            c.guid,
            c.name,
            gm.guildid
        FROM {$charDb}.`characters` c
        INNER JOIN {$charDb}.`guild_member` gm
            ON gm.guid = c.guid
        WHERE c.account = ?
          AND c.guid = ?
          AND gm.guildid = ?
        LIMIT 1
    ");

    if (!$stmt) {
        error_log('FCM_GUILD_CHAT_CHARACTER_PREP_FAIL: ' . $conn->error);
        fcm_json(false, '길드 캐릭터 정보를 확인하지 못했습니다.');
    }

    $stmt->bind_param('iii', $accountId, $charGuid, $guildId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function get_guild_online_count(mysqli $conn, string $charDb, int $guildId): int
{
    if ($guildId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM {$charDb}.`guild_member` gm
        INNER JOIN {$charDb}.`characters` c
            ON c.guid = gm.guid
        WHERE gm.guildid = ?
          AND c.online = 1
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $guildId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['cnt'] ?? 0);
}

function guild_chat_touch_presence(mysqli $conn, string $communityDb, int $guildId, int $accountId, int $characterGuid, string $characterName): void
{
    if ($guildId <= 0 || $accountId <= 0 || $characterGuid <= 0 || $characterName === '') {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO {$communityDb}.`guild_chat_presence`
            (guild_id, account_id, character_guid, character_name, is_active, last_seen_at, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, 1, NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            account_id = VALUES(account_id),
            character_name = VALUES(character_name),
            is_active = 1,
            last_seen_at = NOW(),
            updated_at = NOW()
    ");

    if (!$stmt) {
        error_log('FCM_GUILD_CHAT_PRESENCE_TOUCH_PREP_FAIL: ' . $conn->error);
        return;
    }

    $stmt->bind_param('iiis', $guildId, $accountId, $characterGuid, $characterName);
    $stmt->execute();
    $stmt->close();
}

function guild_chat_leave_presence(mysqli $conn, string $communityDb, int $guildId, int $accountId, int $characterGuid): void
{
    if ($guildId <= 0 || $accountId <= 0 || $characterGuid <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        UPDATE {$communityDb}.`guild_chat_presence`
        SET is_active = 0,
            updated_at = NOW()
        WHERE guild_id = ?
          AND account_id = ?
          AND character_guid = ?
    ");

    if (!$stmt) {
        error_log('FCM_GUILD_CHAT_PRESENCE_LEAVE_PREP_FAIL: ' . $conn->error);
        return;
    }

    $stmt->bind_param('iii', $guildId, $accountId, $characterGuid);
    $stmt->execute();
    $stmt->close();
}

function get_guild_app_presence_members(mysqli $conn, string $communityDb, int $guildId): array
{
    if ($guildId <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            guild_id,
            account_id,
            character_guid,
            character_name,
            UNIX_TIMESTAMP(last_seen_at) AS last_seen_ts
        FROM {$communityDb}.`guild_chat_presence`
        WHERE guild_id = ?
          AND is_active = 1
          AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
        ORDER BY character_name ASC
        LIMIT 100
    ");

    if (!$stmt) {
        error_log('FCM_GUILD_CHAT_PRESENCE_LIST_PREP_FAIL: ' . $conn->error);
        return [];
    }

    $stmt->bind_param('i', $guildId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];

    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'guild_id'        => (int)$row['guild_id'],
            'account_id'      => (int)$row['account_id'],
            'character_guid'  => (int)$row['character_guid'],
            'character_name'  => (string)$row['character_name'],
            'last_seen_ts'    => (int)$row['last_seen_ts'],
        ];
    }

    $stmt->close();

    return $rows;
}

function guild_chat_map_name(int $mapId): string
{
    return match ($mapId) {
        0   => '동부 왕국',
        1   => '칼림도어',
        30  => '알터랙 계곡',
        33  => '그림자송곳니 성채',
        36  => '죽음의 폐광',
        43  => '통곡의 동굴',
        47  => '가시덩굴 우리',
        48  => '검은심연 나락',
        70  => '울다만',
        90  => '놈리건',
        109 => '가라앉은 사원',
        129 => '가시덩굴 구릉',
        189 => '붉은십자군 수도원',
        209 => '줄파락',
        229 => '검은바위 첨탑',
        230 => '검은바위 나락',
        249 => '오닉시아의 둥지',
        269 => '검은늪',
        309 => '줄구룹',
        329 => '스트라솔름',
        349 => '마라우돈',
        369 => '깊은굴 지하철',
        409 => '화산 심장부',
        469 => '검은날개 둥지',
        489 => '전쟁노래 협곡',
        509 => '안퀴라즈 폐허',
        529 => '아라시 분지',
        530 => '아웃랜드',
        531 => '안퀴라즈 사원',
        532 => '카라잔',
        533 => '낙스라마스',
        534 => '하이잘 산 전투',
        540 => '으스러진 손의 전당',
        542 => '피의 용광로',
        543 => '지옥불 성루',
        544 => '마그테리돈의 둥지',
        545 => '증기 저장고',
        546 => '지하수렁',
        547 => '강제 노역소',
        548 => '불뱀 제단',
        550 => '폭풍우 요새',
        552 => '알카트라즈',
        553 => '신록의 정원',
        554 => '메카나르',
        555 => '어둠의 미궁',
        556 => '세데크 전당',
        557 => '마나 무덤',
        558 => '아키나이 납골당',
        559 => '나그란드 투기장',
        560 => '옛 힐스브래드 구릉지',
        562 => '칼날 산맥 투기장',
        564 => '검은 사원',
        565 => '그룰의 둥지',
        568 => '줄아만',
        571 => '노스렌드',
        572 => '로데론의 폐허',
        574 => '우트가드 성채',
        575 => '우트가드 첨탑',
        576 => '마력의 탑',
        578 => '마력의 눈',
        580 => '태양샘 고원',
        585 => '마법학자의 정원',
        595 => '옛 스트라솔름',
        599 => '돌의 전당',
        600 => '드락타론 성채',
        601 => '아졸네룹',
        602 => '번개의 전당',
        603 => '울두아르',
        604 => '군드락',
        608 => '보랏빛 요새',
        615 => '흑요석 성소',
        616 => '영원의 눈',
        617 => '달라란 하수도',
        618 => '용맹의 투기장',
        619 => '안카헤트',
        624 => '아카본 석실',
        631 => '얼음왕관 성채',
        632 => '영혼의 제련소',
        649 => '십자군의 시험장',
        650 => '용사의 시험장',
        658 => '사론의 구덩이',
        668 => '투영의 전당',
        default => '맵 ' . $mapId,
    };
}

function guild_chat_location_label(int $mapId, int $zoneId): string
{
    return guild_chat_map_name($mapId);
}

function get_guild_online_members(mysqli $conn, string $charDb, int $guildId): array
{
    if ($guildId <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            c.guid,
            c.name,
            c.level,
            c.`class`,
            c.`map`,
            c.zone,
            c.position_x,
            c.position_y,
            c.position_z
        FROM {$charDb}.`guild_member` gm
        INNER JOIN {$charDb}.`characters` c
            ON c.guid = gm.guid
        WHERE gm.guildid = ?
          AND c.online = 1
        ORDER BY c.name ASC
        LIMIT 100
    ");

    if (!$stmt) {
        error_log('FCM_GUILD_CHAT_ONLINE_MEMBERS_PREP_FAIL: ' . $conn->error);
        return [];
    }

    $stmt->bind_param('i', $guildId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];

    while ($row = $res->fetch_assoc()) {
        $mapId = (int)($row['map'] ?? 0);
        $zoneId = (int)($row['zone'] ?? 0);

        $rows[] = [
            'guid'       => (int)($row['guid'] ?? 0),
            'name'       => (string)($row['name'] ?? ''),
            'level'      => (int)($row['level'] ?? 0),
            'class'      => (int)($row['class'] ?? 0),
            'map'        => $mapId,
            'zone'       => $zoneId,
            'position_x' => round((float)($row['position_x'] ?? 0), 1),
            'position_y' => round((float)($row['position_y'] ?? 0), 1),
            'position_z' => round((float)($row['position_z'] ?? 0), 1),
            'location'   => guild_chat_location_label($mapId, $zoneId),
        ];
    }

    $stmt->close();

    return $rows;
}

function guild_chat_trim_old_logs(mysqli $conn, string $appDb, int $guildId): void
{
    if ($guildId <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM {$appDb}.`guild_tech_chat`
        WHERE guild_id = ?
        ORDER BY id DESC
        LIMIT 1 OFFSET 30
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $guildId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return;
    }

    $deleteBeforeId = (int)$row['id'];

    if ($deleteBeforeId <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        DELETE FROM {$appDb}.`guild_tech_chat`
        WHERE guild_id = ?
          AND id <= ?
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ii', $guildId, $deleteBeforeId);
    $stmt->execute();
    $stmt->close();
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'online') {
    $guildId = (int)($_POST['guild_id'] ?? $_GET['guild_id'] ?? 0);
    $charGuid = (int)($_POST['char_guid'] ?? $_GET['char_guid'] ?? 0);

    $char = get_guild_character($conn, $charDb, $accountId, $guildId, $charGuid);

    if (!$char) {
        fcm_json(false, '길드 접속자 확인 권한이 없습니다.');
    }

    guild_chat_touch_presence($conn, $communityDb, $guildId, $accountId, (int)$char['guid'], (string)$char['name']);

    $members = get_guild_online_members($conn, $charDb, $guildId);
    $appMembers = get_guild_app_presence_members($conn, $communityDb, $guildId);

    fcm_json(true, '길드 접속자를 불러왔습니다.', [
        'online_count'   => count($members),
        'online_members' => $members,
        'app_count'      => count($appMembers),
        'app_members'    => $appMembers,
        'data'           => $members,
    ]);
}

if ($action === 'leave') {
    $guildId = (int)($_POST['guild_id'] ?? $_GET['guild_id'] ?? 0);
    $charGuid = (int)($_POST['char_guid'] ?? $_GET['char_guid'] ?? 0);

    $char = get_guild_character($conn, $charDb, $accountId, $guildId, $charGuid);

    if (!$char) {
        fcm_json(false, '길드 채팅 권한이 없습니다.');
    }

    guild_chat_leave_presence($conn, $communityDb, $guildId, $accountId, (int)$char['guid']);

    fcm_json(true, '앱 채팅 접속 상태를 종료했습니다.');
}

if ($action === 'list') {
    $guildId = (int)($_POST['guild_id'] ?? $_GET['guild_id'] ?? 0);
    $charGuid = (int)($_POST['char_guid'] ?? $_GET['char_guid'] ?? 0);
    $afterId = (int)($_POST['after_id'] ?? $_GET['after_id'] ?? 0);

    $char = get_guild_character($conn, $charDb, $accountId, $guildId, $charGuid);

    if (!$char) {
        fcm_json(false, '길드 채팅 권한이 없습니다.');
    }

    guild_chat_touch_presence($conn, $communityDb, $guildId, $accountId, (int)$char['guid'], (string)$char['name']);

    if ($afterId > 0) {
        $stmt = $conn->prepare("
            SELECT
                id,
                guild_id,
                character_guid,
                character_name,
                message,
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at
            FROM {$appDb}.`guild_tech_chat`
            WHERE guild_id = ?
              AND id > ?
            ORDER BY id ASC
            LIMIT 30
        ");

        if (!$stmt) {
            error_log('FCM_GUILD_CHAT_LIST_APPEND_PREP_FAIL: ' . $conn->error);
            fcm_json(false, '길드 채팅을 불러오지 못했습니다.');
        }

        $stmt->bind_param('ii', $guildId, $afterId);
    } else {
        $stmt = $conn->prepare("
            SELECT
                id,
                guild_id,
                character_guid,
                character_name,
                message,
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at
            FROM {$appDb}.`guild_tech_chat`
            WHERE guild_id = ?
            ORDER BY id DESC
            LIMIT 30
        ");

        if (!$stmt) {
            error_log('FCM_GUILD_CHAT_LIST_PREP_FAIL: ' . $conn->error);
            fcm_json(false, '길드 채팅을 불러오지 못했습니다.');
        }

        $stmt->bind_param('i', $guildId);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];

    while ($row = $res->fetch_assoc()) {
        $msg = trim((string)$row['message']);
        $isCenterNotice = in_array($msg, [
            '로그인하였습니다.',
            '길드에 가입하였습니다.',
            '길드 테크트리에 참여했습니다.',
        ], true);

        $rows[] = [
            'id'               => (int)$row['id'],
            'guild_id'         => (int)$row['guild_id'],
            'character_guid'   => (int)$row['character_guid'],
            'character_name'   => (string)$row['character_name'],
            'message'          => guild_chat_message_for_app((string)$row['message']),
            'created_at'       => (string)$row['created_at'],
            'is_center_notice' => $isCenterNotice ? 1 : 0,
        ];
    }

    $stmt->close();

    if ($afterId <= 0) {
        $rows = array_reverse($rows);
    }

    $lastId = $afterId;

    foreach ($rows as $row) {
        if ((int)$row['id'] > $lastId) {
            $lastId = (int)$row['id'];
        }
    }

    fcm_json(true, '길드 채팅을 불러왔습니다.', [
        'mode'         => $afterId > 0 ? 'append' : 'full',
        'last_id'      => $lastId,
        'online_count' => get_guild_online_count($conn, $charDb, $guildId),
        'app_count'    => count(get_guild_app_presence_members($conn, $communityDb, $guildId)),
        'data'         => $rows,
    ]);
}

if ($action === 'send') {
    $guildId = (int)($_POST['guild_id'] ?? 0);
    $charGuid = (int)($_POST['char_guid'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));

    if ($guildId <= 0 || $charGuid <= 0) {
        fcm_json(false, '길드 또는 캐릭터 정보가 올바르지 않습니다.');
    }

    $message = guild_chat_clean_text($message);

    if ($message === '') {
        fcm_json(false, '내용을 입력하세요.');
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($message, 'UTF-8') > 255) {
            $message = mb_substr($message, 0, 255, 'UTF-8');
        }
    } elseif (strlen($message) > 255) {
        $message = substr($message, 0, 255);
    }

    $char = get_guild_character($conn, $charDb, $accountId, $guildId, $charGuid);

    if (!$char) {
        fcm_json(false, '길드 채팅 권한이 없습니다.');
    }

    $characterGuid = (int)$char['guid'];
    $characterName = (string)$char['name'];

    guild_chat_touch_presence($conn, $communityDb, $guildId, $accountId, $characterGuid, $characterName);

    $stmt = $conn->prepare("
        INSERT INTO {$appDb}.`guild_tech_chat`
            (guild_id, account_id, character_guid, character_name, message)
        VALUES
            (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log('FCM_GUILD_CHAT_SEND_PREP_FAIL: ' . $conn->error);
        fcm_json(false, '길드 채팅 전송에 실패했습니다.');
    }

    $stmt->bind_param('iiiss', $guildId, $accountId, $characterGuid, $characterName, $message);
    $stmt->execute();
    $chatId = (int)$stmt->insert_id;
    $stmt->close();

    guild_chat_trim_old_logs($conn, $appDb, $guildId);

    $soapResult = guild_chat_send_soap($guildId, $characterName, $message);
    $soapOk = !empty($soapResult['ok']);
    $soapMsg = (string)($soapResult['message'] ?? '');
    $soapCommand = (string)($soapResult['command'] ?? '');

    if (!$soapOk) {
        error_log('FCM_GUILD_CHAT_SOAP_FAIL: ' . $soapMsg . ' / ' . $soapCommand);

        fcm_json(false, 'DB에는 저장됐지만 SOAP 전송에 실패했습니다. ' . $soapMsg, [
            'id'           => $chatId,
            'last_id'      => $chatId,
            'soap_ok'      => false,
            'soap_msg'     => $soapMsg,
            'soap_command' => $soapCommand,
        ]);
    }

    fcm_json(true, '전송되었습니다.', [
        'id'           => $chatId,
        'last_id'      => $chatId,
        'soap_ok'      => true,
        'soap_msg'     => $soapMsg,
        'soap_command' => $soapCommand,
    ]);
}

fcm_json(false, '잘못된 요청입니다.');