<?php
declare(strict_types=1);

// Dedicated, login-free app feedback ingestion. Existing Google-login review form is unchanged.
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function anonymous_review_reply(int $status, string $message, bool $success = false): void
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $success, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    anonymous_review_reply(405, 'POST 요청만 허용합니다.');
}

$mediaType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($mediaType !== 'application/json') {
    anonymous_review_reply(415, 'JSON 형식의 요청이 필요합니다.');
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    anonymous_review_reply(413, '입력 내용이 너무 깁니다.');
}
$raw = file_get_contents('php://input', false, null, 0, 16385);
if (!is_string($raw) || strlen($raw) > 16384) {
    anonymous_review_reply(413, '입력 내용이 너무 깁니다.');
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    anonymous_review_reply(400, '잘못된 요청입니다.');
}

// Only the separately released FamilySafe application is enabled for anonymous reviews.
// A package name is not proof of installation or Google Play tester membership.
$appId = trim((string)($data['package_name'] ?? ''));
if ($appId !== 'pe.kr.forum.familysafe') {
    anonymous_review_reply(400, '허용되지 않은 앱입니다.');
}

$kind = trim((string)($data['kind'] ?? ''));
if (!in_array($kind, ['오류 신고', '개선 제안', '기타 의견'], true)) {
    anonymous_review_reply(400, '의견 종류를 선택해 주세요.');
}
$message = trim((string)($data['content'] ?? ''));
if (mb_strlen($message, 'UTF-8') < 2 || mb_strlen($message, 'UTF-8') > 4000) {
    anonymous_review_reply(400, '의견은 2자 이상 4,000자 이하로 입력해 주세요.');
}
$version = trim((string)($data['app_version'] ?? ''));
if (!preg_match('/\A[a-zA-Z0-9._+\-]{1,30}\z/D', $version)) {
    anonymous_review_reply(400, '앱 버전 정보가 잘못되었습니다.');
}
// Never accept names, email addresses, GPS coordinates or device IDs for this endpoint.
$reviewContent = '[' . $kind . '] ' . $message . "\n" . '[앱 버전: ' . $version . ']';

try {
    $pdo = gamelab_db();

    // Limit automated / accidental submissions without requiring a login.
    // IP is not stored in cleartext; records older than 48 hours are cleaned periodically.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ipHash = hash('sha256', gmdate('Y-m-d') . '|familysafe-review|' . $ip);
    $windowHour = intdiv(time(), 3600);
    $stmt = $pdo->prepare(
        'INSERT INTO gamelab_anonymous_review_rate (ip_hash, window_hour, attempts)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1'
    );
    $stmt->execute([$ipHash, $windowHour]);
    $stmt = $pdo->prepare(
        'SELECT attempts FROM gamelab_anonymous_review_rate
         WHERE ip_hash = ? AND window_hour = ?'
    );
    $stmt->execute([$ipHash, $windowHour]);
    if ((int)$stmt->fetchColumn() > 5) {
        anonymous_review_reply(429, '의견 등록 횟수가 많습니다. 잠시 후 다시 시도해 주세요.');
    }
    if (random_int(1, 100) === 1) {
        $stmt = $pdo->prepare(
            'DELETE FROM gamelab_anonymous_review_rate WHERE window_hour < ?'
        );
        $stmt->execute([$windowHour - 48]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO gamelab_reviews
          (content_type, content_id, parent_id, google_sub, user_name, user_email, content, is_admin, created_at)
         VALUES (?, ?, NULL, ?, ?, ?, ?, 0, NOW())'
    );
    $stmt->execute(['app', $appId, '', '익명', '', $reviewContent]);
    anonymous_review_reply(201, '의견이 등록되었습니다.', true);
} catch (Throwable $error) {
    error_log('FamilySafe anonymous review failed: ' . $error->getMessage());
    anonymous_review_reply(503, '현재 의견을 저장할 수 없습니다. 잠시 후 다시 시도해 주세요.');
}
