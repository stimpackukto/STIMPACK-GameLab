<?php
// /api/community/my_characters.php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $accountId = community_require_session();
    $conn = community_char_db();
    $stmt = $conn->prepare("\n        SELECT c.guid, c.name, c.level, c.race, c.class, c.online, c.logout_time,\n               IFNULL(gm.guildid, 0) AS guild_id, IFNULL(g.name, '') AS guild_name\n        FROM characters c\n        LEFT JOIN guild_member gm ON gm.guid = c.guid\n        LEFT JOIN guild g ON g.guildid = gm.guildid\n        WHERE c.account = ?\n        ORDER BY c.level DESC, c.name ASC\n    ");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $characters = [];
    while ($row = $rs->fetch_assoc()) {
        $characters[] = community_character_array($row);
    }
    $stmt->close();
    $conn->close();
    community_json(['ok' => true, 'characters' => $characters]);
} catch (Throwable $e) {
    community_json(['ok' => false, 'message' => '캐릭터 목록 API 오류: ' . $e->getMessage(), 'characters' => []]);
}
