<?php
declare(strict_types=1);

require_once __DIR__ . '/fcm_common.php';

$conn = fcm_db();
$session = fcm_require_auth($conn);
$communityDb = fcm_ident(FCM_COMMUNITY_DB);
$accountId = (int)$session['account_id'];

$raw = (string)($_POST['enabled_categories'] ?? '[]');
$decoded = json_decode($raw, true);

if (!is_array($decoded)) {
    fcm_json(false, '저장할 체크리스트 값이 올바르지 않습니다.');
}

$enabledMap = [];

foreach ($decoded as $code) {
    $code = trim((string)$code);

    if ($code !== '' && preg_match('/^[a-zA-Z0-9_\\-]+$/', $code)) {
        $enabledMap[$code] = true;
    }
}

$conn->begin_transaction();

try {
    $validCodes = [];
    $sql = "
        SELECT code
        FROM {$communityDb}.`fcm_notification_category`
        WHERE is_active = 1
    ";
    $res = $conn->query($sql);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $code = (string)$row['code'];
            $validCodes[$code] = true;
        }
    }

    $sql = "
        INSERT INTO {$communityDb}.`fcm_user_preference`
            (account_id, category_code, is_enabled, updated_at)
        VALUES
            (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            is_enabled = VALUES(is_enabled),
            updated_at = NOW()
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('FCM_PREF_PREP_FAIL: ' . $conn->error);
    }

    foreach ($validCodes as $code => $_) {
        $isEnabled = isset($enabledMap[$code]) ? 1 : 0;
        $stmt->bind_param('isi', $accountId, $code, $isEnabled);

        if (!$stmt->execute()) {
            throw new RuntimeException('FCM_PREF_EXEC_FAIL: ' . $stmt->error);
        }
    }

    $stmt->close();
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log($e->getMessage());
    fcm_json(false, '체크리스트 저장에 실패했습니다.');
}

fcm_json(true, '저장되었습니다.');
