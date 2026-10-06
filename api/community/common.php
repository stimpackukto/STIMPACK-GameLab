<?php
// /api/community/common.php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function community_json(array $data): void
{
    if (!array_key_exists('success', $data)) {
        $data['success'] = (bool)($data['ok'] ?? false);
    }
    if (!array_key_exists('message', $data) && array_key_exists('msg', $data)) {
        $data['message'] = $data['msg'];
    }
    if (!array_key_exists('msg', $data) && array_key_exists('message', $data)) {
        $data['msg'] = $data['message'];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function community_post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function community_post_first(array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            return trim((string)$_POST[$key]);
        }
    }
    return $default;
}

function community_db(?string $schemaKey = null): mysqli
{
    return db($schemaKey);
}

function community_char_db(): mysqli
{
    return community_db('characters');
}

function community_app_schema(): string
{
    return (string)COMMUNITY_DB_SCHEMA;
}

function community_app_schema_sql(): string
{
    return '`' . str_replace('`', '``', community_app_schema()) . '`';
}

function community_app_db(): mysqli
{
    $conn = community_db();
    $conn->select_db(community_app_schema());
    return $conn;
}

function community_table_exists(mysqli $conn, string $schema, string $table): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
    $stmt->bind_param('ss', $schema, $table);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function community_column_exists(mysqli $conn, string $schema, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1');
    $stmt->bind_param('sss', $schema, $table, $column);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

function community_require_session(): int
{
    $token = community_post('session_token');
    if ($token === '') {
        community_json(['ok' => false, 'message' => '세션 토큰이 없습니다.']);
    }

    $conn = community_db();
    $schema = community_app_schema_sql();
    $stmt = $conn->prepare("SELECT account_id FROM {$schema}.app_sessions WHERE session_token = ? AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        community_json(['ok' => false, 'message' => '세션이 만료되었습니다.']);
    }

    return (int)$row['account_id'];
}

function community_team_from_race(int $race): int
{
    return in_array($race, [1, 3, 4, 7, 11], true) ? 0 : 1;
}

function community_last_seen_text($logoutTime): string
{
    if ($logoutTime === null || $logoutTime === '' || $logoutTime === '0000-00-00 00:00:00') {
        return '';
    }

    $time = is_numeric($logoutTime) ? (int)$logoutTime : (strtotime((string)$logoutTime) ?: 0);
    return $time > 0 ? date('Y-m-d H:i', $time) : '';
}

function community_map_name(int $mapId): string
{
    $names = [
        649 => '십자군의 시험장',
        616 => '영원의 눈',
        615 => '흑요석 성소',
        603 => '울두아르',
        624 => '아카본 석실',
        533 => '낙스라마스',
        631 => '얼음왕관 성채',
    ];
    return $names[$mapId] ?? ('Map ' . $mapId);
}

function community_difficulty_name(int $difficulty): string
{
    return match ($difficulty) {
        0 => '일반',
        1 => '영웅',
        2 => '10인 일반',
        3 => '25인 일반',
        4 => '10인 영웅',
        5 => '25인 영웅',
        default => '난이도 ' . $difficulty,
    };
}

function community_character_array(array $row): array
{
    $race = (int)$row['race'];
    return [
        'guid' => (int)$row['guid'],
        'name' => (string)$row['name'],
        'level' => (int)$row['level'],
        'race' => $race,
        'class_id' => (int)$row['class'],
        'team' => community_team_from_race($race),
        'guild_id' => (int)($row['guild_id'] ?? 0),
        'guild_name' => (string)($row['guild_name'] ?? ''),
        'online' => ((int)($row['online'] ?? 0)) === 1,
        'last_seen_text' => community_last_seen_text((int)($row['logout_time'] ?? 0)),
    ];
}

function community_check_character_owner(int $accountId, int $characterGuid): array
{
    if ($accountId <= 0 || $characterGuid <= 0) {
        community_json(['ok' => false, 'message' => '캐릭터 정보가 올바르지 않습니다.']);
    }

    $conn = community_char_db();
    $stmt = $conn->prepare("\n        SELECT c.guid, c.name, c.level, c.race, c.class, c.online, c.logout_time,\n               IFNULL(gm.guildid, 0) AS guild_id, IFNULL(g.name, '') AS guild_name\n        FROM characters c\n        LEFT JOIN guild_member gm ON gm.guid = c.guid\n        LEFT JOIN guild g ON g.guildid = gm.guildid\n        WHERE c.guid = ? AND c.account = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('ii', $characterGuid, $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        community_json(['ok' => false, 'message' => '선택한 캐릭터가 계정에 없습니다.']);
    }

    return $row;
}

function community_count_query(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    try {
        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $value = (int)($stmt->get_result()->fetch_column() ?: 0);
        $stmt->close();
        return $value;
    } catch (Throwable $e) {
        return 0;
    }
}
