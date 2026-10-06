<?php
declare(strict_types=1);

require_once __DIR__ . '/fcm_common.php';

$conn = fcm_db();
$session = fcm_require_auth($conn);
$communityDb = fcm_ident(FCM_COMMUNITY_DB);
$accountId = (int)$session['account_id'];

$sql = "
    SELECT
        c.code,
        c.name,
        c.description,
        COALESCE(p.is_enabled, 1) AS is_enabled
    FROM {$communityDb}.`fcm_notification_category` c
    LEFT JOIN {$communityDb}.`fcm_user_preference` p
        ON p.account_id = ?
       AND p.category_code = c.code
    WHERE c.is_active = 1
    ORDER BY c.sort_order ASC, c.id ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('FCM_CATEGORIES_PREP_FAIL: ' . $conn->error);
    fcm_json(false, '체크리스트 조회에 실패했습니다.');
}

$stmt->bind_param('i', $accountId);
$stmt->execute();
$res = $stmt->get_result();
$categories = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = [
            'code'        => (string)$row['code'],
            'name'        => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
            'is_enabled'  => ((int)$row['is_enabled']) === 1,
        ];
    }
}

$stmt->close();

fcm_json(true, '목록을 불러왔습니다.', [
    'categories' => $categories,
]);
