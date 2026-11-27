<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/devtest.php';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow', true);
header('Content-Type: application/json; charset=UTF-8');

function devtest_confirm_json(int $status, bool $ok, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST', true, 405);
    devtest_confirm_json(405, false, 'POST 요청만 허용합니다.');
}

$idText = trim((string)($_POST['application_id'] ?? ''));
$token = trim((string)($_POST['token'] ?? ''));
if ($idText === '' || !ctype_digit($idText) || strlen($idText) > 12
    || (int)$idText < 1 || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
    devtest_confirm_json(400, false, '확인 링크 정보가 올바르지 않습니다.');
}

try {
    // The secret emailed to the original owner is the credential.
    // No Google session or CSRF cookie is required for this token-authorized POST.
    $db = gamelab_db();
    $isNew = gamelab_devtest_confirm_token($db, (int)$idText, $token);
} catch (RuntimeException $error) {
    devtest_confirm_json(403, false, '유효하지 않은 확인 링크입니다.');
} catch (Throwable $error) {
    error_log('[GAME LAB DEVTEST] token confirmation failed: ' . $error->getMessage());
    devtest_confirm_json(500, false, '서버에서 확인을 처리하지 못했습니다.');
}

devtest_confirm_json(200, true, $isNew ? '오너 확인이 기록되었습니다.' : '이미 오너 확인이 완료된 신청입니다.');
