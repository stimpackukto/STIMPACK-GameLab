<?php
declare(strict_types=1);

require_once __DIR__ . '/fcm_common.php';

/*
 * 홈페이지용 FCM 발송 함수
 *
 * 사용 예:
 * require_once __DIR__ . '/api/community/fcm/fcm_sender.php';
 * fcm_notify_category_new_post('1');
 *
 * 카테고리 1 = 서버공지
 * fcm_user_preference.category_code = '1'
 * fcm_user_preference.is_enabled = 1
 * fcm_device_token.is_active = 1
 */

function fcm_send_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function fcm_send_post_form(string $url, array $fields): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'errno'     => $errno,
        'error'     => $error,
        'body'      => $body === false ? '' : (string)$body,
    ];
}

function fcm_send_post_json(string $url, array $headers, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return [
            'http_code' => 0,
            'errno'     => 0,
            'error'     => 'JSON 인코딩 실패',
            'body'      => '',
        ];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'errno'     => $errno,
        'error'     => $error,
        'body'      => $body === false ? '' : (string)$body,
    ];
}

function fcm_send_load_service_account(): array
{
    if (!defined('FCM_SERVICE_ACCOUNT_JSON') || FCM_SERVICE_ACCOUNT_JSON === '') {
        return [
            'success' => false,
            'message' => 'FCM_SERVICE_ACCOUNT_JSON 설정이 없습니다.',
        ];
    }

    $path = (string)FCM_SERVICE_ACCOUNT_JSON;

    if (!is_file($path)) {
        return [
            'success' => false,
            'message' => 'Firebase 서비스 계정 JSON 파일을 찾지 못했습니다.',
            'path' => $path,
        ];
    }

    $json = file_get_contents($path);

    if ($json === false || trim($json) === '') {
        return [
            'success' => false,
            'message' => 'Firebase 서비스 계정 JSON 파일을 읽지 못했습니다.',
            'path' => $path,
        ];
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [
            'success' => false,
            'message' => 'Firebase 서비스 계정 JSON 파싱에 실패했습니다.',
        ];
    }

    foreach (['project_id', 'client_email', 'private_key'] as $key) {
        if (empty($data[$key]) || !is_string($data[$key])) {
            return [
                'success' => false,
                'message' => 'Firebase 서비스 계정 JSON 값이 올바르지 않습니다.',
                'missing' => $key,
            ];
        }
    }

    $data['success'] = true;

    return $data;
}

function fcm_send_access_token(array $serviceAccount): array
{
    $cacheFile = sys_get_temp_dir() . '/stimpack_fcm_access_token_' . md5((string)$serviceAccount['client_email']) . '.json';

    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);

        if (
            is_array($cached)
            && !empty($cached['access_token'])
            && !empty($cached['expires_at'])
            && (int)$cached['expires_at'] > time() + 120
        ) {
            return [
                'success' => true,
                'access_token' => (string)$cached['access_token'],
                'cached' => true,
            ];
        }
    }

    $now = time();

    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
    ];

    $claim = [
        'iss'   => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $unsignedJwt =
        fcm_send_b64url(json_encode($header, JSON_UNESCAPED_SLASHES)) .
        '.' .
        fcm_send_b64url(json_encode($claim, JSON_UNESCAPED_SLASHES));

    $signature = '';

    $ok = openssl_sign(
        $unsignedJwt,
        $signature,
        (string)$serviceAccount['private_key'],
        OPENSSL_ALGO_SHA256
    );

    if (!$ok) {
        return [
            'success' => false,
            'message' => 'Firebase JWT 서명에 실패했습니다.',
        ];
    }

    $jwt = $unsignedJwt . '.' . fcm_send_b64url($signature);

    $res = fcm_send_post_form('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);

    $decoded = json_decode($res['body'], true);

    if (
        $res['errno'] !== 0
        || $res['http_code'] < 200
        || $res['http_code'] >= 300
        || !is_array($decoded)
        || empty($decoded['access_token'])
    ) {
        return [
            'success' => false,
            'message' => 'Firebase OAuth 토큰 발급에 실패했습니다.',
            'http_code' => $res['http_code'],
            'curl_errno' => $res['errno'],
            'curl_error' => $res['error'],
            'response' => $res['body'],
        ];
    }

    $accessToken = (string)$decoded['access_token'];
    $expiresIn = (int)($decoded['expires_in'] ?? 3600);
    $expiresAt = time() + max(300, $expiresIn - 60);

    @file_put_contents($cacheFile, json_encode([
        'access_token' => $accessToken,
        'expires_at' => $expiresAt,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return [
        'success' => true,
        'access_token' => $accessToken,
        'cached' => false,
    ];
}

function fcm_get_category_name(mysqli $conn, string $categoryCode): string
{
    $communityDb = fcm_ident(FCM_COMMUNITY_DB);

    $sql = "
        SELECT name
        FROM {$communityDb}.`fcm_notification_category`
        WHERE code = ?
           OR id = ?
        LIMIT 1
    ";

    try {
        $categoryId = ctype_digit($categoryCode) ? (int)$categoryCode : 0;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $categoryCode, $categoryId);
        $stmt->execute();

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        $stmt->close();

        if ($row && trim((string)$row['name']) !== '') {
            return trim((string)$row['name']);
        }
    } catch (Throwable $e) {
        error_log('FCM_CATEGORY_NAME_FAIL: ' . $e->getMessage());
    }

    if ($categoryCode === '1') {
        return '서버공지';
    }

    return '알림';
}

function fcm_get_category_target_tokens(mysqli $conn, string $categoryCode): array
{
    $communityDb = fcm_ident(FCM_COMMUNITY_DB);

    $categoryId = ctype_digit($categoryCode) ? (int)$categoryCode : 0;

    $sql = "
        SELECT DISTINCT
            d.id,
            d.account_id,
            d.device_id,
            d.fcm_token,
            d.app_version,
            d.platform,
            d.last_seen_at
        FROM {$communityDb}.`fcm_device_token` d
        INNER JOIN {$communityDb}.`fcm_notification_category` c
            ON c.is_active = 1
           AND (
                c.code = ?
                OR c.id = ?
           )
        LEFT JOIN {$communityDb}.`fcm_user_preference` p
            ON p.account_id = d.account_id
           AND (
                p.category_code = c.code
                OR p.category_code = CAST(c.id AS CHAR)
           )
        WHERE d.is_active = 1
          AND d.fcm_token <> ''
          AND COALESCE(p.is_enabled, c.default_enabled) = 1
        ORDER BY d.last_seen_at DESC, d.id DESC
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $categoryCode, $categoryId);
        $stmt->execute();

        $res = $stmt->get_result();
        $rows = [];

        while ($res && ($row = $res->fetch_assoc())) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    } catch (Throwable $e) {
        error_log('FCM_TARGET_TOKEN_LOAD_FAIL: ' . $e->getMessage());
        return [];
    }
}

function fcm_deactivate_device_token(mysqli $conn, int $deviceTokenId): void
{
    if ($deviceTokenId <= 0) {
        return;
    }

    $communityDb = fcm_ident(FCM_COMMUNITY_DB);

    $sql = "
        UPDATE {$communityDb}.`fcm_device_token`
        SET is_active = 0,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $deviceTokenId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('FCM_DEACTIVATE_TOKEN_FAIL: ' . $e->getMessage());
    }
}

function fcm_send_to_token(
    string $projectId,
    string $accessToken,
    string $targetToken,
    string $title,
    string $body,
    array $data = []
): array {
    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';

    $stringData = [];

    foreach ($data as $key => $value) {
        $stringData[(string)$key] = (string)$value;
    }

    $payload = [
        'message' => [
            'token' => $targetToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'data' => $stringData,
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'sound' => 'default',
                    'channel_id' => 'default',
                ],
            ],
        ],
    ];

    return fcm_send_post_json($url, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json; charset=utf-8',
    ], $payload);
}

function fcm_notify_category(
    string $categoryCode,
    string $title,
    string $body,
    array $data = []
): array {
    $categoryCode = trim($categoryCode);

    if ($categoryCode === '') {
        return [
            'success' => false,
            'message' => '알림 카테고리 코드가 없습니다.',
        ];
    }

    $conn = fcm_db();

    $targets = fcm_get_category_target_tokens($conn, $categoryCode);

    if (!$targets) {
        return [
            'success' => true,
            'message' => '알림 대상자가 없습니다.',
            'category_code' => $categoryCode,
            'target_count' => 0,
            'sent_count' => 0,
            'fail_count' => 0,
        ];
    }

    $serviceAccount = fcm_send_load_service_account();

    if (empty($serviceAccount['success'])) {
        return $serviceAccount;
    }

    $accessTokenResult = fcm_send_access_token($serviceAccount);

    if (empty($accessTokenResult['success'])) {
        return $accessTokenResult;
    }

    $projectId = (string)$serviceAccount['project_id'];
    $accessToken = (string)$accessTokenResult['access_token'];

    $sentCount = 0;
    $failCount = 0;
    $errors = [];

    $data = array_merge([
        'category_code' => $categoryCode,
        'sent_at' => date('Y-m-d H:i:s'),
    ], $data);

    foreach ($targets as $target) {
        $deviceTokenId = (int)$target['id'];
        $targetToken = trim((string)$target['fcm_token']);

        if ($targetToken === '') {
            continue;
        }

        $res = fcm_send_to_token(
            $projectId,
            $accessToken,
            $targetToken,
            $title,
            $body,
            $data
        );

        $decoded = json_decode($res['body'], true);

        if ($res['errno'] === 0 && $res['http_code'] >= 200 && $res['http_code'] < 300) {
            $sentCount++;
            continue;
        }

        $failCount++;

        $errorCode = '';

        if (is_array($decoded)) {
            $errorCode = (string)($decoded['error']['details'][0]['errorCode'] ?? $decoded['error']['status'] ?? '');
        }

        if ($errorCode === 'UNREGISTERED' || $errorCode === 'INVALID_ARGUMENT') {
            fcm_deactivate_device_token($conn, $deviceTokenId);
        }

        if (count($errors) < 10) {
            $errors[] = [
                'device_token_id' => $deviceTokenId,
                'account_id' => (int)$target['account_id'],
                'http_code' => $res['http_code'],
                'error_code' => $errorCode,
                'response' => $decoded ?: $res['body'],
            ];
        }
    }

    return [
        'success' => true,
        'message' => 'FCM 카테고리 알림 발송 완료',
        'category_code' => $categoryCode,
        'target_count' => count($targets),
        'sent_count' => $sentCount,
        'fail_count' => $failCount,
        'errors' => $errors,
    ];
}

function fcm_notify_category_new_post(
    string $categoryCode,
    string $postTitle = '',
    int $postId = 0,
    string $postUrl = ''
): array {
    $conn = fcm_db();

    $categoryName = fcm_get_category_name($conn, $categoryCode);

    $title = $categoryName;
    $body = $categoryName . ' 새게시글이 등록되었습니다.';

    if ($postTitle !== '') {
        $body = $categoryName . ' 새게시글이 등록되었습니다.';
    }

    return fcm_notify_category($categoryCode, $title, $body, [
        'type' => 'new_post',
        'category_name' => $categoryName,
        'post_id' => $postId > 0 ? (string)$postId : '',
        'post_title' => $postTitle,
        'post_url' => $postUrl,
    ]);
}

function fcm_get_category_account_tokens(mysqli $conn, string $categoryCode, int $accountId): array
{
    if ($accountId <= 0) {
        return [];
    }

    $communityDb = fcm_ident(FCM_COMMUNITY_DB);

    $sql = "
        SELECT DISTINCT d.id, d.account_id, d.device_id, d.fcm_token
        FROM {$communityDb}.`fcm_device_token` d
        INNER JOIN {$communityDb}.`fcm_user_preference` p
            ON p.account_id = d.account_id
        WHERE d.account_id = ?
          AND d.is_active = 1
          AND d.fcm_token <> ''
          AND p.category_code = ?
          AND p.is_enabled = 1
        ORDER BY d.last_seen_at DESC, d.id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $accountId, $categoryCode);
    $stmt->execute();

    $res = $stmt->get_result();
    $rows = [];

    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function fcm_notify_category_account(
    string $categoryCode,
    int $accountId,
    string $title,
    string $body,
    array $data = []
): array {
    $conn = fcm_db();

    $targets = fcm_get_category_account_tokens($conn, $categoryCode, $accountId);

    if (!$targets) {
        return [
            'success' => true,
            'message' => '알림 대상자가 없습니다.',
            'category_code' => $categoryCode,
            'account_id' => $accountId,
            'target_count' => 0,
            'sent_count' => 0,
            'fail_count' => 0,
        ];
    }

    $serviceAccount = fcm_send_load_service_account();
    if (empty($serviceAccount['success'])) {
        return $serviceAccount;
    }

    $accessTokenResult = fcm_send_access_token($serviceAccount);
    if (empty($accessTokenResult['success'])) {
        return $accessTokenResult;
    }

    $projectId = (string)$serviceAccount['project_id'];
    $accessToken = (string)$accessTokenResult['access_token'];

    $sentCount = 0;
    $failCount = 0;

    $data = array_merge([
        'category_code' => $categoryCode,
        'account_id' => (string)$accountId,
        'sent_at' => date('Y-m-d H:i:s'),
    ], $data);

    foreach ($targets as $target) {
        $res = fcm_send_to_token(
            $projectId,
            $accessToken,
            trim((string)$target['fcm_token']),
            $title,
            $body,
            $data
        );

        if ($res['errno'] === 0 && $res['http_code'] >= 200 && $res['http_code'] < 300) {
            $sentCount++;
        } else {
            $failCount++;
        }
    }

    return [
        'success' => true,
        'message' => 'FCM 계정 지정 알림 발송 완료',
        'category_code' => $categoryCode,
        'account_id' => $accountId,
        'target_count' => count($targets),
        'sent_count' => $sentCount,
        'fail_count' => $failCount,
    ];
}