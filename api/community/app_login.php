<?php
// /api/community/app_login.php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

try {
    $input = app_input();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $deviceId = trim((string)($input['device_id'] ?? ''));
    $fcmToken = trim((string)($input['fcm_token'] ?? ''));
    $deviceInfo = trim((string)($input['device_info'] ?? $deviceId));

    if ($username === '' || $password === '') {
        app_json(false, '계정 이름과 비밀번호를 입력하세요.');
    }

    $account = app_login_account($username, $password);
    if (!$account) {
        app_json(false, '계정 정보가 올바르지 않습니다.');
    }

    $token = app_issue_token((int)$account['id'], (string)$account['username'], $deviceId);

    if ($fcmToken !== '') {
        $db = db_conn(db_name('app'));

        if (table_exists($db, 'push_tokens')) {
            // 기존 홈페이지 로그인 코드와 동일한 테이블 우선 사용
            $stmt = $db->prepare('INSERT INTO push_tokens (account_id, fcm, device_info, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    account_id = VALUES(account_id),
                    fcm = VALUES(fcm),
                    device_info = VALUES(device_info),
                    updated_at = VALUES(updated_at)');
            $stmt->bind_param('iss', $account['id'], $fcmToken, $deviceInfo);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $db->prepare('INSERT INTO app_device_tokens(account_id, device_id, fcm_token, platform)
                VALUES (?, ?, ?, "android")
                ON DUPLICATE KEY UPDATE fcm_token=VALUES(fcm_token), updated_at=NOW()');
            $stmt->bind_param('iss', $account['id'], $deviceId, $fcmToken);
            $stmt->execute();
            $stmt->close();
        }
    }

    app_json(true, '로그인 성공', [
        'token' => $token,
        'account_id' => (int)$account['id'],
        'account_name' => (string)$account['username'],
    ]);
} catch (Throwable $e) {
    app_json(false, '로그인 서버 오류: ' . $e->getMessage(), [], 200);
}
