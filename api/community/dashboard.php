<?php
// /api/community/dashboard.php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function dashboard_points(mysqli $conn, int $accountId): array
{
    $point = 0;
    $rp = 0;
    $frost = 0;

    if (community_table_exists($conn, 'appdata', 'account_data')) {
        foreach (['point', 'points', 'Point'] as $col) {
            if (community_column_exists($conn, 'appdata', 'account_data', $col)) {
                $stmt = $conn->prepare("SELECT IFNULL({$col}, 0) FROM appdata.account_data WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $accountId);
                $stmt->execute();
                $point = (int)($stmt->get_result()->fetch_column() ?: 0);
                $stmt->close();
                break;
            }
        }
        foreach (['rp', 'RP'] as $col) {
            if (community_column_exists($conn, 'appdata', 'account_data', $col)) {
                $stmt = $conn->prepare("SELECT IFNULL({$col}, 0) FROM appdata.account_data WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $accountId);
                $stmt->execute();
                $rp = (int)($stmt->get_result()->fetch_column() ?: 0);
                $stmt->close();
                break;
            }
        }
    }

    return ['point' => $point, 'rp' => $rp, 'frost' => $frost, 'note' => '서버 계정 기준 재화'];
}

function dashboard_mission_today(mysqli $conn, int $accountId, int $guid): array
{
    if (!community_table_exists($conn, 'appdata', 'mission_events')) {
        return ['title' => '오늘의 수행미션', 'description' => '등록된 수행미션 테이블이 없습니다.', 'reward_text' => '', 'completed' => false, 'progress_text' => ''];
    }

    $title = '오늘의 수행미션';
    $description = '오늘 등록된 수행미션을 확인하세요.';
    $reward = '';

    $cols = [];
    foreach (['title','name','description','content','reward_text','reward_point','point'] as $col) {
        if (community_column_exists($conn, 'appdata', 'mission_events', $col)) {
            $cols[] = $col;
        }
    }

    try {
        $sql = 'SELECT * FROM appdata.mission_events ORDER BY id DESC LIMIT 1';
        $row = $conn->query($sql)->fetch_assoc();
        if ($row) {
            $title = (string)($row['title'] ?? $row['name'] ?? '오늘의 수행미션');
            $description = (string)($row['description'] ?? $row['content'] ?? $description);
            $reward = (string)($row['reward_text'] ?? '');
            if ($reward === '' && isset($row['reward_point'])) $reward = 'Point ' . (int)$row['reward_point'];
            if ($reward === '' && isset($row['point'])) $reward = 'Point ' . (int)$row['point'];
        }
    } catch (Throwable $e) {
    }

    return ['title' => $title, 'description' => $description, 'reward_text' => $reward, 'completed' => false, 'progress_text' => '진행 상태 확인 준비 중'];
}

function dashboard_weekly_raid(mysqli $conn): array
{
    if (!community_table_exists($conn, 'appdata', 'instance_damage_mult')) {
        return ['title' => '주간 공격대 이벤트', 'map_id' => 0, 'dmg_mult' => '', 'party_check_count' => 0, 'description' => '주간 공격대 이벤트 테이블이 없습니다.'];
    }

    $raidMapIds = [649, 616, 615, 603, 624, 533, 631];
    $raidMapIdList = implode(',', $raidMapIds);

    try {
        $mapColumn = 'map_id';
        if (!community_column_exists($conn, 'appdata', 'instance_damage_mult', 'map_id')) {
            if (community_column_exists($conn, 'appdata', 'instance_damage_mult', 'map')) {
                $mapColumn = 'map';
            } else {
                return ['title' => '주간 공격대 이벤트', 'map_id' => 0, 'dmg_mult' => '', 'party_check_count' => 0, 'description' => 'instance_damage_mult 테이블에 map_id 컬럼이 없습니다.'];
            }
        }

        $mapColumnSql = '`' . str_replace('`', '``', $mapColumn) . '`';
        $row = $conn->query("
            SELECT {$mapColumnSql} AS raid_map_id, dmg_mult, party_check_count
            FROM appdata.instance_damage_mult
            WHERE {$mapColumnSql} IN ({$raidMapIdList})
            ORDER BY CAST(dmg_mult AS DECIMAL(10, 2)) DESC,
                     CASE WHEN party_check_count = 3 THEN 0 ELSE 1 END,
                     {$mapColumnSql} ASC
            LIMIT 1
        ")->fetch_assoc();

        if ($row) {
            $mapId = (int)$row['raid_map_id'];
            $mapName = community_map_name($mapId);
            return [
                'title' => $mapName,
                'map_id' => $mapId,
                'dmg_mult' => (string)$row['dmg_mult'],
                'party_check_count' => (int)$row['party_check_count'],
                'description' => $mapName . ' 주간 공격대 이벤트가 적용 중입니다.',
            ];
        }
    } catch (Throwable $e) {
    }

    return ['title' => '주간 공격대 이벤트', 'map_id' => 0, 'dmg_mult' => '', 'party_check_count' => 0, 'description' => '진행 중인 주간 공격대 이벤트가 없습니다.'];
}

function dashboard_event_status(mysqli $conn, int $accountId): array
{
    $active = community_table_exists($conn, 'appdata', 'mission_events') ? community_count_query($conn, 'SELECT COUNT(*) FROM appdata.mission_events') : 0;
    return ['active_count' => $active, 'participated_count' => 0, 'text' => $active > 0 ? "확인 가능한 이벤트 {$active}개" : '진행 중인 이벤트 정보가 없습니다.'];
}

function dashboard_reward_status(mysqli $conn, int $accountId, int $guid): array
{
    $schema = community_app_schema_sql();
    $count = community_count_query($conn, "SELECT COUNT(*) FROM {$schema}.app_alerts WHERE account_id = ? AND alert_type = 'REWARD' AND is_read = 0", 'i', [$accountId]);
    return ['available_count' => $count, 'text' => $count > 0 ? "수령 확인이 필요한 보상 {$count}개" : '수령 가능한 보상이 없습니다.'];
}

function dashboard_mail_status(mysqli $conn, int $guid): array
{
    $count = community_count_query($conn, 'SELECT COUNT(*) FROM characters.mail WHERE receiver = ? AND checked = 0 AND deliver_time <= UNIX_TIMESTAMP()', 'i', [$guid]);
    return ['unread_count' => $count, 'text' => $count > 0 ? "읽지 않은 우편 {$count}개" : '새 우편이 없습니다.'];
}

function dashboard_private_shop_status(mysqli $conn, int $accountId, int $guid): array
{
    $schema = community_app_schema_sql();
    $count = community_count_query($conn, "SELECT COUNT(*) FROM {$schema}.app_alerts WHERE account_id = ? AND alert_type = 'SHOP' AND is_read = 0", 'i', [$accountId]);
    return ['notify_count' => $count, 'text' => $count > 0 ? "개인상점 알림 {$count}개" : '개인상점 알림이 없습니다.'];
}

function dashboard_instance_locks(mysqli $conn, int $guid): array
{
    $items = [];
    try {
        $stmt = $conn->prepare("\n            SELECT i.map, i.difficulty, i.resettime\n            FROM characters.character_instance ci\n            INNER JOIN characters.instance i ON i.id = ci.instance\n            WHERE ci.guid = ? AND i.resettime > UNIX_TIMESTAMP()\n            ORDER BY i.resettime ASC\n            LIMIT 20\n        ");
        $stmt->bind_param('i', $guid);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $mapId = (int)$row['map'];
            $items[] = [
                'map_id' => $mapId,
                'map_name' => community_map_name($mapId),
                'difficulty' => community_difficulty_name((int)$row['difficulty']),
                'reset_text' => date('Y-m-d H:i', (int)$row['resettime']) . ' 초기화',
            ];
        }
        $stmt->close();
    } catch (Throwable $e) {
    }
    return $items;
}

function dashboard_collection(mysqli $conn, int $accountId, int $guid): array
{
    $completed = 0;
    $total = 0;

    if (community_table_exists($conn, 'appdata', 'item_collections')) {
        $total = community_count_query($conn, 'SELECT COUNT(*) FROM appdata.item_collections');
    }
    if (community_table_exists($conn, 'appdata', 'item_collection_progress')) {
        $completed = community_count_query($conn, 'SELECT COUNT(*) FROM appdata.item_collection_progress WHERE character_guid = ? AND completed = 1', 'i', [$guid]);
    }

    $percent = $total > 0 ? (int)floor(($completed / $total) * 100) : 0;
    return ['completed' => $completed, 'total' => $total, 'percent' => $percent, 'text' => $total > 0 ? "컬렉션 {$completed}/{$total} 완료" : '컬렉션 정보가 없습니다.'];
}

function dashboard_titles(mysqli $conn, array $character): array
{
    $title = '';
    if (isset($character['chosenTitle'])) {
        $title = (string)$character['chosenTitle'];
    }
    return ['current_title' => $title, 'honor_title' => '', 'text' => $title !== '' ? '현재 선택된 칭호가 있습니다.' : '명예타이틀 정보가 없습니다.'];
}

function dashboard_ranking(mysqli $conn, int $guid): array
{
    $rank = 0;
    $total = 0;
    try {
        $total = community_count_query($conn, 'SELECT COUNT(*) FROM characters.characters WHERE level > 0');
        $stmt = $conn->prepare('SELECT COUNT(*) + 1 FROM characters.characters WHERE totalHonorPoints > (SELECT totalHonorPoints FROM characters.characters WHERE guid = ? LIMIT 1)');
        $stmt->bind_param('i', $guid);
        $stmt->execute();
        $rank = (int)($stmt->get_result()->fetch_column() ?: 0);
        $stmt->close();
    } catch (Throwable $e) {
    }
    return ['my_rank' => $rank, 'total' => $total, 'text' => $rank > 0 ? "명예점수 기준 {$rank}위" : '랭킹 정보가 없습니다.'];
}

function dashboard_guild_online(mysqli $conn, array $character): array
{
    $guildId = (int)($character['guild_id'] ?? 0);
    if ($guildId <= 0) return [];

    $items = [];
    $stmt = $conn->prepare("\n        SELECT c.guid, c.name, c.level, c.class, c.online, c.logout_time, IFNULL(gr.rname, '') AS rank_name\n        FROM characters.guild_member gm\n        INNER JOIN characters.characters c ON c.guid = gm.guid\n        LEFT JOIN characters.guild_rank gr ON gr.guildid = gm.guildid AND gr.rid = gm.rank\n        WHERE gm.guildid = ?\n        ORDER BY c.online DESC, c.level DESC, c.name ASC\n        LIMIT 50\n    ");
    $stmt->bind_param('i', $guildId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $items[] = [
            'guid' => (int)$row['guid'],
            'name' => (string)$row['name'],
            'level' => (int)$row['level'],
            'class_id' => (int)$row['class'],
            'rank_name' => (string)$row['rank_name'],
            'online' => ((int)$row['online']) === 1,
            'last_seen_text' => community_last_seen_text((int)$row['logout_time']),
        ];
    }
    $stmt->close();
    return $items;
}

function dashboard_alerts(mysqli $conn, int $accountId): array
{
    $schema = community_app_schema_sql();
    $items = [];
    try {
        $stmt = $conn->prepare("SELECT alert_type, title, body, created_at FROM {$schema}.app_alerts WHERE account_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 20");
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $items[] = [
                'type' => (string)$row['alert_type'],
                'title' => (string)$row['title'],
                'body' => (string)$row['body'],
                'created_at' => (string)$row['created_at'],
            ];
        }
        $stmt->close();
    } catch (Throwable $e) {
    }
    return $items;
}

try {
    $accountId = community_require_session();
    $characterGuid = (int)community_post('character_guid');
    $characterRow = community_check_character_owner($accountId, $characterGuid);
    $character = community_character_array($characterRow);

    $conn = community_db();
    $dashboard = [
        'character' => $character,
        'points' => dashboard_points($conn, $accountId),
        'mission_today' => dashboard_mission_today($conn, $accountId, $characterGuid),
        'weekly_raid' => dashboard_weekly_raid($conn),
        'event_status' => dashboard_event_status($conn, $accountId),
        'reward_status' => dashboard_reward_status($conn, $accountId, $characterGuid),
        'mail_status' => dashboard_mail_status($conn, $characterGuid),
        'private_shop_status' => dashboard_private_shop_status($conn, $accountId, $characterGuid),
        'instance_locks' => dashboard_instance_locks($conn, $characterGuid),
        'collection_progress' => dashboard_collection($conn, $accountId, $characterGuid),
        'titles' => dashboard_titles($conn, $characterRow),
        'ranking' => dashboard_ranking($conn, $characterGuid),
        'guild_online' => dashboard_guild_online($conn, $characterRow),
        'alerts' => dashboard_alerts($conn, $accountId),
    ];
    $conn->close();

    community_json(['ok' => true, 'message' => '', 'dashboard' => $dashboard]);
} catch (Throwable $e) {
    community_json(['ok' => false, 'message' => '대시보드 API 오류: ' . $e->getMessage(), 'dashboard' => new stdClass()]);
}
