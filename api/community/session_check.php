<?php
// /api/community/session_check.php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $accountId = community_require_session();
    community_json(['ok' => true, 'account_id' => $accountId]);
} catch (Throwable $e) {
    community_json(['ok' => false, 'message' => '세션 확인 오류: ' . $e->getMessage()]);
}
