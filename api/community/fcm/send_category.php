<?php
declare(strict_types=1);

// Custom Update - 2026-07-26
// Mail notifications are delivered only to devices registered to the recipient account.
// Mail notifications without account_id are rejected instead of being broadcast globally.

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

function fcm_category_json(bool $success, string $message, array $extra = []): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }

    echo json_encode(array_merge([
        'success' => $success,
        'ok'      => $success ? 1 : 0,
        'message' => $message,
        'msg'     => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    if (ob_get_length() !== false) {
        ob_clean();
    }

    echo json_encode([
        'success' => false,
        'ok' => 0,
        'message' => 'FCM 발송 처리 중 PHP 오류가 발생했습니다.',
        'msg' => 'FCM 발송 처리 중 PHP 오류가 발생했습니다.',
        'error' => [
            'type' => (int)$error['type'],
            'message' => (string)$error['message'],
            'file' => basename((string)$error['file']),
            'line' => (int)$error['line'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

require_once __DIR__ . '/fcm_common.php';

function fcm_category_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function fcm_category_post_form(string $url, array $fields): array
{
    $bodyText = http_build_query($fields);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $bodyText,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $httpCode = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string)$headerLine, $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }

    return [
        'http_code' => $httpCode,
        'errno'     => $body === false ? 1 : 0,
        'error'     => $body === false ? 'file_get_contents failed' : '',
        'body'      => $body === false ? '' : (string)$body,
    ];
}

function fcm_category_post_json(string $url, array $headers, array $payload): array
{
    $bodyText = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $bodyText === false ? '{}' : $bodyText,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $httpCode = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string)$headerLine, $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }

    return [
        'http_code' => $httpCode,
        'errno'     => $body === false ? 1 : 0,
        'error'     => $body === false ? 'file_get_contents failed' : '',
        'body'      => $body === false ? '' : (string)$body,
    ];
}

function fcm_category_is_quiet_hours(): bool
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $hm = (int)$now->format('Hi');

    return $hm >= 2359 || $hm < 800;
}

function fcm_category_request_value(string $name, string $default = ''): string
{
    return trim((string)($_GET[$name] ?? $_POST[$name] ?? $default));
}

function fcm_category_target_account_id(): int
{
    return (int)($_GET['account_id'] ?? $_POST['account_id'] ?? 0);
}

function fcm_category_recipient_account_id(): int
{
    return (int)($_GET['recipient_account_id'] ?? $_POST['recipient_account_id'] ?? 0);
}

function fcm_category_is_common_notice(int $accountId): bool
{
    return $accountId <= 0;
}

function fcm_category_resolve_codes(mysqli $conn, string $category): array
{
    $communityDb = fcm_ident(FCM_COMMUNITY_DB);
    $categoryCodes = [];

    if (ctype_digit($category)) {
        $categoryId = (int)$category;

        $stmt = $conn->prepare("
            SELECT code
            FROM {$communityDb}.`fcm_notification_category`
            WHERE id = ?
              AND is_active = 1
            LIMIT 1
        ");

        if (!$stmt) {
            fcm_category_json(false, '알림 카테고리 조회 준비에 실패했습니다.', [
                'error' => $conn->error,
            ]);
        }

        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $categoryCodes[] = trim((string)$row['code']);
        }

        $stmt->close();
    } else {
        $categoryCodes[] = trim($category);
    }

    return array_values(array_unique(array_filter(
        $categoryCodes,
        static fn($value): bool => trim((string)$value) !== ''
    )));
}

function fcm_category_is_mail_notification(string $type, array $categoryCodes): bool
{
    $normalizedType = strtolower(trim($type));

    $mailTypes = [
        'mail',
        'mail_received',
        'new_mail',
        'wow_mail',
        'game_mail',
        'character_mail',
        'mail_notice',
    ];

    if (in_array($normalizedType, $mailTypes, true)) {
        return true;
    }

    foreach ($categoryCodes as $code) {
        $normalizedCode = strtolower(trim((string)$code));

        if (
            str_contains($normalizedCode, 'mail') ||
            str_contains($normalizedCode, '메일')
        ) {
            return true;
        }
    }

    return false;
}

function fcm_category_load_service_account(): array
{
    if (!defined('FCM_SERVICE_ACCOUNT_JSON') || FCM_SERVICE_ACCOUNT_JSON === '') {
        fcm_category_json(false, 'FCM_SERVICE_ACCOUNT_JSON 설정이 없습니다.');
    }

    $path = (string)FCM_SERVICE_ACCOUNT_JSON;

    if (!is_file($path)) {
        fcm_category_json(false, 'Firebase 서비스 계정 JSON 파일을 찾지 못했습니다.', [
            'path' => $path,
        ]);
    }

    $json = file_get_contents($path);

    if ($json === false || trim($json) === '') {
        fcm_category_json(false, 'Firebase 서비스 계정 JSON 파일을 읽지 못했습니다.', [
            'path' => $path,
        ]);
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        fcm_category_json(false, 'Firebase 서비스 계정 JSON 파싱에 실패했습니다.');
    }

    foreach (['project_id', 'client_email', 'private_key'] as $key) {
        if (empty($data[$key]) || !is_string($data[$key])) {
            fcm_category_json(false, 'Firebase 서비스 계정 JSON 값이 올바르지 않습니다.', [
                'missing' => $key,
            ]);
        }
    }

    return $data;
}

function fcm_category_access_token(array $serviceAccount): string
{
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
        fcm_category_b64url((string)json_encode($header, JSON_UNESCAPED_SLASHES)) .
        '.' .
        fcm_category_b64url((string)json_encode($claim, JSON_UNESCAPED_SLASHES));

    $signature = '';

    $ok = openssl_sign(
        $unsignedJwt,
        $signature,
        $serviceAccount['private_key'],
        OPENSSL_ALGO_SHA256
    );

    if (!$ok) {
        fcm_category_json(false, 'Firebase JWT 서명에 실패했습니다.');
    }

    $jwt = $unsignedJwt . '.' . fcm_category_b64url($signature);

    $response = fcm_category_post_form('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);

    $decoded = json_decode($response['body'], true);

    if (
        $response['errno'] !== 0 ||
        $response['http_code'] < 200 ||
        $response['http_code'] >= 300 ||
        !is_array($decoded) ||
        empty($decoded['access_token'])
    ) {
        fcm_category_json(false, 'Firebase OAuth 토큰 발급에 실패했습니다.', [
            'http_code' => $response['http_code'],
            'curl_errno' => $response['errno'],
            'curl_error' => $response['error'],
            'response' => $response['body'],
        ]);
    }

    return (string)$decoded['access_token'];
}

function fcm_category_devices(
    mysqli $conn,
    array $categoryCodes,
    int $accountId
): array {
    $communityDb = fcm_ident(FCM_COMMUNITY_DB);

    if (!$categoryCodes) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($categoryCodes), '?'));
    $types = str_repeat('s', count($categoryCodes));

    if ($accountId > 0) {
        $sql = "
            SELECT DISTINCT
                d.id,
                d.account_id,
                d.device_id,
                d.fcm_token,
                d.app_version,
                d.platform,
                d.is_active,
                d.last_seen_at,
                d.created_at
            FROM {$communityDb}.`fcm_device_token` d
            INNER JOIN {$communityDb}.`fcm_notification_category` c
                ON c.code IN ({$placeholders})
               AND c.is_active = 1
            LEFT JOIN {$communityDb}.`fcm_user_preference` p
                ON p.account_id = d.account_id
               AND p.category_code = c.code
            WHERE d.is_active = 1
              AND d.fcm_token <> ''
              AND d.account_id = ?
              AND COALESCE(p.is_enabled, 1) = 1
            ORDER BY d.id DESC
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            fcm_category_json(false, '토큰 조회 준비에 실패했습니다.', [
                'error' => $conn->error,
            ]);
        }

        $bindTypes = $types . 'i';
        $bindValues = array_merge($categoryCodes, [$accountId]);
        $stmt->bind_param($bindTypes, ...$bindValues);
    } else {
        $sql = "
            SELECT DISTINCT
                d.id,
                d.account_id,
                d.device_id,
                d.fcm_token,
                d.app_version,
                d.platform,
                d.is_active,
                d.last_seen_at,
                d.created_at
            FROM {$communityDb}.`fcm_device_token` d
            INNER JOIN {$communityDb}.`fcm_notification_category` c
                ON c.code IN ({$placeholders})
               AND c.is_active = 1
            LEFT JOIN {$communityDb}.`fcm_user_preference` p
                ON p.account_id = d.account_id
               AND p.category_code = c.code
            WHERE d.is_active = 1
              AND d.fcm_token <> ''
              AND COALESCE(p.is_enabled, 1) = 1
            ORDER BY d.id DESC
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            fcm_category_json(false, '토큰 조회 준비에 실패했습니다.', [
                'error' => $conn->error,
            ]);
        }

        $stmt->bind_param($types, ...$categoryCodes);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function fcm_category_send_message(
    string $projectId,
    string $accessToken,
    string $targetToken,
    string $title,
    string $body,
    array $data,
    bool $dataOnly
): array {
    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';

    $stringData = [];

    foreach ($data as $key => $value) {
        $stringData[(string)$key] = (string)$value;
    }

    $message = [
        'token' => $targetToken,
        'data' => $stringData,
        'android' => [
            'priority' => 'HIGH',
        ],
    ];

    if (!$dataOnly) {
        $message['notification'] = [
            'title' => $title,
            'body'  => $body,
        ];

        $message['android']['notification'] = [
            'sound' => 'default',
            'channel_id' => 'default',
        ];
    }

    return fcm_category_post_json($url, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json; charset=utf-8',
    ], [
        'message' => $message,
    ]);
}

$secret = fcm_category_request_value('secret');

if (!defined('FCM_TEST_SECRET') || FCM_TEST_SECRET === '') {
    fcm_category_json(false, 'FCM_TEST_SECRET 설정이 없습니다.');
}

if (!hash_equals((string)FCM_TEST_SECRET, $secret)) {
    fcm_category_json(false, '테스트 권한이 없습니다.');
}

$category = fcm_category_request_value('category');
$title = fcm_category_request_value('title');
$body = fcm_category_request_value('body');
$type = fcm_category_request_value('type', 'category_notice');
$accountId = fcm_category_target_account_id();
$recipientAccountId = fcm_category_recipient_account_id();

if ($category === '' || $title === '' || $body === '') {
    fcm_category_json(false, 'category, title, body 값이 필요합니다.');
}

$conn = fcm_db();
$categoryCodes = fcm_category_resolve_codes($conn, $category);

if (!$categoryCodes) {
    fcm_category_json(false, '유효한 알림 카테고리를 찾지 못했습니다.', [
        'category' => $category,
    ]);
}

$isMailNotification = fcm_category_is_mail_notification($type, $categoryCodes);

if ($isMailNotification) {
    if ($accountId <= 0) {
        fcm_category_json(false, '메일 알림에는 수신 계정 account_id가 반드시 필요합니다.', [
            'type' => $type,
            'category' => $category,
            'category_codes' => $categoryCodes,
            'send_scope' => 'rejected_missing_account_id',
        ]);
    }

    if ($recipientAccountId > 0 && $recipientAccountId !== $accountId) {
        fcm_category_json(false, 'account_id와 recipient_account_id가 일치하지 않습니다.', [
            'account_id' => $accountId,
            'recipient_account_id' => $recipientAccountId,
            'send_scope' => 'rejected_account_mismatch',
        ]);
    }

    $recipientAccountId = $accountId;
}

if (fcm_category_is_common_notice($accountId) && fcm_category_is_quiet_hours()) {
    fcm_category_json(true, '야간 공통 알림 제한 시간이라 발송하지 않았습니다.', [
        'category' => $category,
        'category_codes' => $categoryCodes,
        'quiet_hours' => '23:59-08:00',
        'send_scope' => 'common',
        'target_count' => 0,
        'sent_count' => 0,
        'fail_count' => 0,
    ]);
}

$devices = fcm_category_devices($conn, $categoryCodes, $accountId);

if (!$devices) {
    fcm_category_json(true, '발송할 활성 FCM 토큰이 없습니다.', [
        'category' => $category,
        'category_codes' => $categoryCodes,
        'account_id' => $accountId,
        'send_scope' => $accountId > 0 ? 'account' : 'common',
        'target_count' => 0,
        'sent_count' => 0,
        'fail_count' => 0,
    ]);
}

$serviceAccount = fcm_category_load_service_account();
$projectId = (string)$serviceAccount['project_id'];
$accessToken = fcm_category_access_token($serviceAccount);

$data = [
    'type' => $type,
    'category' => $category,
    'category_code' => (string)$categoryCodes[0],
    'title' => $title,
    'body' => $body,
    'account_id' => $accountId > 0 ? (string)$accountId : '',
    'recipient_account_id' => $recipientAccountId > 0 ? (string)$recipientAccountId : '',
    'mail_scope' => $isMailNotification ? 'strict_account' : '',
    'post_id' => fcm_category_request_value('post_id'),
    'post_title' => fcm_category_request_value('post_title'),
    'post_url' => fcm_category_request_value('post_url'),
    'source' => 'stimpack_send_category',
    'sent_at' => date('Y-m-d H:i:s'),
];

$sentCount = 0;
$failCount = 0;
$errors = [];

foreach ($devices as $device) {
    $targetToken = trim((string)$device['fcm_token']);

    if ($targetToken === '') {
        continue;
    }

    if ($isMailNotification && (int)$device['account_id'] !== $accountId) {
        $failCount++;

        if (count($errors) < 10) {
            $errors[] = [
                'device_token_id' => (int)$device['id'],
                'account_id' => (int)$device['account_id'],
                'device_id' => (string)$device['device_id'],
                'error' => '메일 수신 계정과 토큰 계정이 일치하지 않아 발송을 차단했습니다.',
            ];
        }

        continue;
    }

    $sendResult = fcm_category_send_message(
        $projectId,
        $accessToken,
        $targetToken,
        $title,
        $body,
        $data,
        $isMailNotification
    );

    $decoded = json_decode($sendResult['body'], true);

    if (
        $sendResult['errno'] === 0 &&
        $sendResult['http_code'] >= 200 &&
        $sendResult['http_code'] < 300
    ) {
        $sentCount++;
        continue;
    }

    $failCount++;

    if (count($errors) < 10) {
        $errors[] = [
            'device_token_id' => (int)$device['id'],
            'account_id' => (int)$device['account_id'],
            'device_id' => (string)$device['device_id'],
            'http_code' => $sendResult['http_code'],
            'curl_errno' => $sendResult['errno'],
            'curl_error' => $sendResult['error'],
            'firebase_response' => $decoded ?: $sendResult['body'],
        ];
    }
}

fcm_category_json(true, 'FCM 카테고리 발송 완료', [
    'category' => $category,
    'category_codes' => $categoryCodes,
    'type' => $type,
    'account_id' => $accountId,
    'recipient_account_id' => $recipientAccountId,
    'send_scope' => $isMailNotification ? 'strict_mail_account' : ($accountId > 0 ? 'account' : 'common'),
    'data_only' => $isMailNotification,
    'target_count' => count($devices),
    'sent_count' => $sentCount,
    'fail_count' => $failCount,
    'errors' => $errors,
]);