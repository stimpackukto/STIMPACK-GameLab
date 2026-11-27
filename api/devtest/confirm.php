<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/devtest.php';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST', true, 405);
    exit('POST 요청만 허용합니다.');
}

$viewer = gamelab_devtest_user();
if ($viewer === null) {
    http_response_code(403);
    exit('앱 등록자의 Google 로그인 정보가 필요합니다.');
}
if (!gamelab_verify_csrf((string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    exit('보안 확인에 실패했습니다. 확인 페이지를 다시 열어 주세요.');
}

$idText = trim((string)($_POST['application_id'] ?? ''));
if ($idText === '' || !ctype_digit($idText) || strlen($idText) > 12
    || (int)$idText < 1) {
    http_response_code(400);
    exit('신청 번호가 올바르지 않습니다.');
}
$applicationId = (int)$idText;

try {
    $db = gamelab_db();
    gamelab_devtest_confirm_application($db, $viewer, $applicationId);
} catch (RuntimeException $error) {
    http_response_code(403);
    exit(htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
} catch (Throwable $error) {
    error_log('[GAME LAB DEVTEST] owner confirm POST failed: ' . $error->getMessage());
    http_response_code(500);
    exit('확인 처리에 실패했습니다. 다시 시도해 주세요.');
}

header('Location: /test/confirm.php?application_id=' . $applicationId . '&done=1', true, 303);
exit;
