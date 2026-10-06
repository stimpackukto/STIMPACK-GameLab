<?php
// /api/community/app_logout.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$input = app_input();
$token = trim((string)($input['token'] ?? ''));
if ($token !== '') {
    $db = db_conn(db_name('app'));
    $stmt = $db->prepare('DELETE FROM app_mobile_sessions WHERE token=?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
}

app_json(true, '로그아웃 완료');
