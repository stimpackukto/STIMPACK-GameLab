<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* POST 요청만 허용 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('잘못된 요청입니다.');
}


/* 로그인 확인 */
$googleUser = $_SESSION['google_user'] ?? [];

$currentSub = trim(
    (string)($googleUser['sub'] ?? '')
);

if ($currentSub === '') {
    http_response_code(401);
    exit('로그인이 필요합니다.');
}


/* 입력값 */
$reviewId = (int)($_POST['review_id'] ?? 0);

$type = trim(
    (string)($_POST['type'] ?? '')
);

$contentId = trim(
    (string)($_POST['content_id'] ?? '')
);

$csrf = (string)(
    $_POST['csrf'] ?? ''
);


if ($reviewId <= 0) {
    http_response_code(400);
    exit('잘못된 리뷰 번호입니다.');
}


if (!in_array($type, ['app', 'game'], true)) {
    http_response_code(400);
    exit('잘못된 콘텐츠 종류입니다.');
}


if (
    $contentId === '' ||
    !preg_match(
        '/^[a-zA-Z0-9._-]{1,191}$/',
        $contentId
    )
) {
    http_response_code(400);
    exit('잘못된 콘텐츠 ID입니다.');
}


/* CSRF 확인 */
if (!gamelab_verify_csrf($csrf)) {
    http_response_code(403);
    exit('잘못된 요청입니다.');
}


$pdo = gamelab_db();


/* 리뷰 조회 */
$stmt = $pdo->prepare(
    '
    SELECT
        id,
        content_type,
        content_id,
        parent_id,
        google_sub,
        is_admin
    FROM gamelab_reviews
    WHERE id = ?
    LIMIT 1
    '
);

$stmt->execute([
    $reviewId
]);

$review = $stmt->fetch();


if (!$review) {
    http_response_code(404);
    exit('이미 삭제되었거나 존재하지 않는 의견입니다.');
}


/* 현재 콘텐츠와 일치하는지 확인 */
if (
    (string)$review['content_type'] !== $type ||
    (string)$review['content_id'] !== $contentId
) {
    http_response_code(400);
    exit('잘못된 삭제 요청입니다.');
}


/* 권한 확인 */
$isAdmin = gamelab_is_admin();

$isOwner =
    hash_equals(
        (string)$review['google_sub'],
        $currentSub
    );


if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    exit('삭제 권한이 없습니다.');
}


/* =========================================================
 * 삭제
 *
 * 부모 리뷰라면 답글도 함께 삭제
 * 답글이라면 해당 답글만 삭제
 * ========================================================= */

$pdo->beginTransaction();

try {

    if (empty($review['parent_id'])) {

        /* 자식 답글 먼저 삭제 */
        $stmt = $pdo->prepare(
            '
            DELETE FROM gamelab_reviews
            WHERE parent_id = ?
            '
        );

        $stmt->execute([
            $reviewId
        ]);
    }


    /* 본문 삭제 */

    $stmt = $pdo->prepare(
        '
        DELETE FROM gamelab_reviews
        WHERE id = ?
        LIMIT 1
        '
    );

    $stmt->execute([
        $reviewId
    ]);


    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    exit('삭제 중 오류가 발생했습니다.');
}


/* 원래 페이지로 이동 */

if ($type === 'app') {

    $returnUrl =
        '/apps/?review=' .
        rawurlencode($contentId) .
        '#' .
        rawurlencode($contentId);

} else {

    $returnUrl =
        '/games/?review=' .
        rawurlencode($contentId) .
        '#' .
        rawurlencode($contentId);
}


header('Location: ' . $returnUrl);
exit;
