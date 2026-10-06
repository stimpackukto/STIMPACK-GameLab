<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/db.php';


/* =========================================================
 * 기본값
 * ========================================================= */

$type = trim(
    (string)($_POST['type'] ?? $_GET['type'] ?? '')
);

$contentId = trim(
    (string)($_POST['id'] ?? $_GET['id'] ?? '')
);

$parentId = (int)(
    $_POST['parent_id']
    ?? $_GET['parent_id']
    ?? 0
);

$error = '';


/* =========================================================
 * type 검사
 * ========================================================= */

if (!in_array($type, ['app', 'game'], true)) {
    http_response_code(400);
    exit('잘못된 콘텐츠 종류입니다.');
}


/* =========================================================
 * content_id 검사
 *
 * app:
 * pe.kr.forum.medicalrecord
 *
 * game:
 * crimescene
 * matgo
 * findculprit
 * ========================================================= */

if ($contentId === '') {
    http_response_code(400);
    exit('콘텐츠 ID가 없습니다.');
}


if (
    !preg_match(
        '/^[a-zA-Z0-9._-]{1,191}$/',
        $contentId
    )
) {
    http_response_code(400);
    exit('잘못된 콘텐츠 ID입니다.');
}


/* =========================================================
 * 로그인 사용자 확인
 * ========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


$googleUser = $_SESSION['google_user'] ?? [];

$googleSub = trim(
    (string)($googleUser['sub'] ?? '')
);

$userEmail = trim(
    (string)(
        $googleUser['email']
        ?? $_SESSION['google_email']
        ?? ''
    )
);

$userName = trim(
    (string)($googleUser['name'] ?? '')
);


$isLoggedIn =
    $googleSub !== ''
    && $userEmail !== '';


/* 이름이 없으면 이메일 앞부분 사용 */

if ($userName === '' && $userEmail !== '') {

    $parts = explode('@', $userEmail);

    $userName = $parts[0] ?? '사용자';
}


$isAdmin = gamelab_is_admin();


/* =========================================================
 * DB
 * ========================================================= */

$pdo = gamelab_db();


/* =========================================================
 * 답글 대상 검사
 * ========================================================= */

$parentReview = null;

if ($parentId > 0) {

    $stmt = $pdo->prepare(
        '
        SELECT
            id,
            content_type,
            content_id,
            parent_id,
            user_name,
            content
        FROM gamelab_reviews
        WHERE id = ?
        LIMIT 1
        '
    );

    $stmt->execute([
        $parentId
    ]);

    $parentReview = $stmt->fetch();


    if (!$parentReview) {

        http_response_code(404);
        exit('원본 의견을 찾을 수 없습니다.');
    }


    /*
     * 다른 앱/게임 리뷰에
     * 잘못 답글 다는 것 방지
     */

    if (
        (string)$parentReview['content_type'] !== $type
        ||
        (string)$parentReview['content_id'] !== $contentId
    ) {

        http_response_code(400);
        exit('잘못된 답글 요청입니다.');
    }


    /*
     * 답글에 답글은 일단 금지
     */

    if (!empty($parentReview['parent_id'])) {

        http_response_code(400);
        exit('답글에는 다시 답글을 작성할 수 없습니다.');
    }
}


/* =========================================================
 * 저장 처리
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        if (!$isLoggedIn) {

            throw new RuntimeException(
                'Google 로그인 후 의견을 작성할 수 있습니다.'
            );
        }


        $csrf = (string)(
            $_POST['csrf']
            ?? ''
        );


        if (!gamelab_verify_csrf($csrf)) {

            throw new RuntimeException(
                '잘못된 요청입니다. 다시 시도해주세요.'
            );
        }


        $content = trim(
            (string)(
                $_POST['content']
                ?? ''
            )
        );


        if ($content === '') {

            throw new RuntimeException(
                '의견 내용을 입력해주세요.'
            );
        }


        if (
            mb_strlen(
                $content,
                'UTF-8'
            ) > 5000
        ) {

            throw new RuntimeException(
                '의견은 5000자 이하로 작성해주세요.'
            );
        }


        /*
         * parent_id가 있으면
         * 관리자만 개발자 답글 작성 가능
         */

        if ($parentId > 0 && !$isAdmin) {

            throw new RuntimeException(
                '개발자 답글은 관리자만 작성할 수 있습니다.'
            );
        }


        $stmt = $pdo->prepare(
            '
            INSERT INTO gamelab_reviews
            (
                content_type,
                content_id,
                parent_id,
                google_sub,
                user_name,
                user_email,
                content,
                is_admin,
                created_at
            )
            VALUES
            (
                :content_type,
                :content_id,
                :parent_id,
                :google_sub,
                :user_name,
                :user_email,
                :content,
                :is_admin,
                NOW()
            )
            '
        );


        $stmt->execute([

            ':content_type' =>
                $type,

            ':content_id' =>
                $contentId,

            ':parent_id' =>
                $parentId > 0
                    ? $parentId
                    : null,

            ':google_sub' =>
                $googleSub,

            ':user_name' =>
                $userName,

            ':user_email' =>
                $userEmail,

            ':content' =>
                $content,

            ':is_admin' =>
                $isAdmin
                    ? 1
                    : 0,
        ]);


        /*
         * 저장 후 해당 목록으로 이동
         */

        if ($type === 'app') {

            $returnUrl =
                '/apps/?review='
                . rawurlencode($contentId)
                . '#'
                . rawurlencode($contentId);

        } else {

            $returnUrl =
                '/games/?review='
                . rawurlencode($contentId)
                . '#'
                . rawurlencode($contentId);
        }


        header(
            'Location: ' . $returnUrl
        );

        exit;


    } catch (Throwable $e) {

        $error = $e->getMessage();
    }
}


/* =========================================================
 * 페이지
 * ========================================================= */

$pageTitle =
    $parentId > 0
        ? '개발자 답글'
        : '의견 남기기';


require dirname(__DIR__) . '/includes/header.php';


function review_h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>


<style>

.review-write-wrap {
    max-width: 760px;
    margin: 0 auto;
}

.review-write-card {
    padding: 28px;

    background: #111827;

    border:
        1px solid
        rgba(255,255,255,.13);

    border-radius: 18px;
}

.review-target {
    margin-bottom: 24px;
    padding: 14px 16px;

    border-radius: 10px;

    background:
        rgba(25,184,255,.08);

    color:
        rgba(255,255,255,.80);
}

.review-target strong {
    color: #19b8ff;
}

.review-login-box {
    padding: 22px;

    text-align: center;

    border:
        1px solid
        rgba(255,255,255,.12);

    border-radius: 12px;

    background:
        rgba(0,0,0,.20);
}

.review-form label {
    display: block;

    margin-bottom: 8px;

    font-weight: 700;
}

.review-form textarea {
    width: 100%;
    min-height: 180px;

    resize: vertical;

    padding: 14px;

    font: inherit;

    line-height: 1.6;

    background: #fff;

    color: #111;

    border: 0;

    border-radius: 6px;
}

.review-buttons {
    display: flex;

    gap: 10px;

    margin-top: 20px;
}

.review-error {
    margin-bottom: 18px;

    padding: 14px;

    background: #481919;

    color: #fff;

    border-radius: 8px;
}

.review-parent {
    margin-bottom: 20px;

    padding: 16px;

    background:
        rgba(255,255,255,.05);

    border-left:
        3px solid #19b8ff;

    border-radius: 6px;
}

.review-parent-name {
    margin-bottom: 7px;

    font-weight: 800;
}

.review-parent-content {
    color:
        rgba(255,255,255,.72);

    line-height: 1.6;
}

</style>


<section class="subhero">

    <div class="container">

        <p class="section-kicker">

            <?= $parentId > 0
                ? 'DEVELOPER REPLY'
                : 'REVIEW & FEEDBACK'
            ?>

        </p>


        <h1>

            <?= $parentId > 0
                ? '개발자 답글'
                : '의견 남기기'
            ?>

        </h1>


        <p>

            <?= $type === 'app'
                ? '앱'
                : '웹게임'
            ?>

            사용 중 느낀 점이나
            개선 의견을 남겨주세요.

        </p>

    </div>

</section>


<section class="section">

<div class="container">

<div class="review-write-wrap">

<div class="review-write-card">


    <div class="review-target">

        대상:

        <strong>

            <?= review_h(
                $contentId
            ) ?>

        </strong>

    </div>


    <?php if ($parentReview): ?>


        <div class="review-parent">

            <div class="review-parent-name">

                <?= review_h(
                    (string)$parentReview[
                        'user_name'
                    ]
                ) ?>

            </div>


            <div class="review-parent-content">

                <?= nl2br(
                    review_h(
                        (string)$parentReview[
                            'content'
                        ]
                    )
                ) ?>

            </div>

        </div>


    <?php endif; ?>


    <?php if ($error !== ''): ?>

        <div class="review-error">

            <?= review_h($error) ?>

        </div>

    <?php endif; ?>


    <?php if (!$isLoggedIn): ?>


        <div class="review-login-box">

            <p>
                의견을 작성하려면
                Google 로그인이 필요합니다.
            </p>


            <p style="margin-top:18px;">

                <a
                    class="button primary"
                    href="/auth/google_login.php"
                >

                    Google 로그인

                </a>

            </p>

        </div>


    <?php else: ?>


        <form
            method="post"
            class="review-form"
        >


            <input
                type="hidden"
                name="type"
                value="<?= review_h($type) ?>"
            >


            <input
                type="hidden"
                name="id"
                value="<?= review_h($contentId) ?>"
            >


            <input
                type="hidden"
                name="parent_id"
                value="<?= $parentId ?>"
            >


            <input
                type="hidden"
                name="csrf"
                value="<?= review_h(
                    gamelab_csrf_token()
                ) ?>"
            >


            <label>
                작성자
            </label>


            <div
                style="
                    margin-bottom:20px;
                    color:rgba(255,255,255,.7);
                "
            >

                <?= $isAdmin
                    ? '개발자'
                    : review_h($userName)
                ?>

            </div>


            <label
                for="review-content"
            >

                <?= $parentId > 0
                    ? '답글'
                    : '의견'
                ?>

            </label>


            <textarea
                id="review-content"
                name="content"
                maxlength="5000"
                required
                placeholder="<?=
                    $parentId > 0
                        ? '답변 내용을 입력하세요.'
                        : '사용하면서 느낀 점, 버그, 개선 의견 등을 자유롭게 남겨주세요.'
                ?>"
            ><?= review_h(
                (string)(
                    $_POST['content']
                    ?? ''
                )
            ) ?></textarea>


            <div class="review-buttons">


                <button
                    type="submit"
                    class="button primary"
                >

                    <?= $parentId > 0
                        ? '답글 등록'
                        : '의견 등록'
                    ?>

                </button>


                <a
                    class="button secondary"
                    href="<?=
                        $type === 'app'
                            ? '/apps/#'
                                . rawurlencode(
                                    $contentId
                                )
                            : '/games/#'
                                . rawurlencode(
                                    $contentId
                                )
                    ?>"
                >

                    취소

                </a>


            </div>


        </form>


    <?php endif; ?>


</div>

</div>

</div>

</section>


<?php
require dirname(__DIR__) . '/includes/footer.php';
?>
