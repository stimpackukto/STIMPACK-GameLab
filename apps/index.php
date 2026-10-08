<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/data.php';
require_once dirname(__DIR__) . '/includes/reviews.php';
require_once dirname(__DIR__) . '/includes/tester.php';


/* =========================================================
 * 로그인 / 관리자
 * ========================================================= */

$isGameLabAdmin = gamelab_is_admin();


$currentGoogleSub = trim(
    (string)(
        $_SESSION['google_user']['sub']
        ?? ''
    )
);


$currentGoogleEmail = trim(
    (string)(
        $_SESSION['google_user']['email']
        ?? $_SESSION['google_email']
        ?? ''
    )
);

/* =========================================================
 * 테스터 상태
 * ========================================================= */

$testerStatus = null;

$canJoinTester = false;


if ($currentGoogleEmail !== '') {

    $testerStatus =
        gamelab_tester_status(
            $currentGoogleEmail
        );


    /*
     * 테스터 DB에 아직 없는 경우에만
     * auth.account 확인
     */

    if ($testerStatus === null) {

        $canJoinTester =
            gamelab_is_wow_account_email(
                $currentGoogleEmail
            );
    }
}


/* =========================================================
 * 리뷰 작성/삭제 후 돌아왔을 때
 * 해당 앱 리뷰 자동 펼침
 * ========================================================= */

$openReviewId = trim(
    (string)(
        $_GET['review']
        ?? ''
    )
);


$pageTitle = '앱';

require dirname(__DIR__) . '/includes/header.php';
?>


<style>

/* =========================================================
 * 상단 관리자 영역
 * ========================================================= */

.app-admin-tools {
    display: flex;
    flex-wrap: wrap;
    align-items: center;

    gap: 10px;

    margin-top: 22px;
}


.admin-login-badge {
    display: inline-flex;
    align-items: center;

    padding: 10px 14px;

    border:
        1px solid rgba(34,197,94,.35);

    border-radius: 8px;

    background:
        rgba(34,197,94,.10);

    color: #70e694;

    font-size: 13px;
    font-weight: 800;
}


/* =========================================================
 * 앱 카드
 *
 * 리뷰가 길어져도 대표이미지는 위쪽 유지
 * ========================================================= */

.project-row {
    align-items: flex-start;
}


.project-row > img {
    align-self: flex-start;

    margin-top: 0;

    object-fit: cover;
    object-position: center;
}


.project-body {
    min-width: 0;

    flex: 1;
}


/* =========================================================
 * 상태 뱃지
 * ========================================================= */

.app-status-badges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;

    gap: 7px;

    margin-bottom: 10px;
}


.app-status-badges .mini-status {
    margin-bottom: 0;
}


/*
 * 출시 뱃지는 개발 상태와 별개
 *
 * 예:
 * [개발중] [출시]
 */

.release-status {
    background: #22c55e !important;

    color: #fff !important;
}


/* =========================================================
 * 설명
 *
 * 기본 2줄
 * 자세히 보기 → 같은 내용 전체 펼침
 * ========================================================= */

.project-description {
    margin: 14px 0 18px;

    color: #d8e7f7;

    line-height: 1.7;

    display: -webkit-box;

    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;

    overflow: hidden;
}


.project-description.expanded {
    display: block;

    overflow: visible;
}


/* =========================================================
 * 버튼
 * ========================================================= */

.project-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;

    gap: 10px;

    margin-top: 14px;
}


.purpose-toggle,
.detail-toggle,
.review-button {
    cursor: pointer;
}


.project-purpose-panel {
    margin: 14px 0 18px;
    padding: 16px 18px;
    border: 1px solid rgba(25,184,255,.22);
    border-radius: 12px;
    background: rgba(5,25,40,.72);
    color: #d8e7f7;
    line-height: 1.75;
}

.project-purpose-panel[hidden] {
    display: none;
}

.project-purpose-panel strong {
    display: block;
    margin-bottom: 8px;
    color: #7dd3fc;
    font-size: 14px;
}


/* =========================================================
 * Google Play
 * ========================================================= */

.google-play-button {
    background:
        rgba(34,197,94,.10) !important;

    border-color:
        rgba(34,197,94,.55) !important;

    color:
        #78e99a !important;

    font-weight: 800;
}


.google-play-button:hover {
    background:
        rgba(34,197,94,.18) !important;
}


/* =========================================================
 * 관리자 수정
 * ========================================================= */

.app-edit-button {
    border-color:
        rgba(255,191,73,.45) !important;

    color:
        #ffc55d !important;
}


.app-edit-button:hover {
    background:
        rgba(255,191,73,.10) !important;
}


/* =========================================================
 * 리뷰 패널
 * ========================================================= */

.review-panel {
    margin-top: 18px;

    padding: 18px;

    border:
        1px solid rgba(25,184,255,.15);

    border-radius: 12px;

    background:
        rgba(0,0,0,.18);
}


.review-panel[hidden] {
    display: none;
}


.review-panel-title {
    margin-bottom: 16px;

    font-size: 17px;
    font-weight: 800;
}


/* =========================================================
 * 리뷰
 * ========================================================= */

.review-item {
    padding: 14px 0;

    border-bottom:
        1px solid rgba(255,255,255,.10);
}


.review-item:last-of-type {
    border-bottom: 0;
}


.review-author {
    margin-bottom: 7px;

    font-weight: 800;
}


.review-author.admin {
    color: #19b8ff;
}


.review-content {
    color:
        rgba(255,255,255,.90);

    line-height: 1.7;
}


.review-date {
    margin-top: 8px;

    color:
        rgba(255,255,255,.45);

    font-size: 12px;
}


/* =========================================================
 * 개발자 답글
 * ========================================================= */

.review-reply {
    margin-left: 26px;

    padding-left: 16px;

    border-left:
        2px solid rgba(25,184,255,.35);
}


/* =========================================================
 * 리뷰 관리
 * ========================================================= */

.review-tools {
    display: flex;
    flex-wrap: wrap;
    align-items: center;

    gap: 8px;

    margin-top: 10px;
}


.review-reply-link {
    display: inline-block;

    padding: 5px 10px;

    border:
        1px solid rgba(25,184,255,.40);

    border-radius: 6px;

    color: #19b8ff;

    font-size: 12px;
    font-weight: 700;

    text-decoration: none;
}


.review-reply-link:hover {
    background:
        rgba(25,184,255,.10);
}


/* =========================================================
 * 삭제
 * ========================================================= */

.review-delete-form {
    display: inline;

    margin: 0;
}


.review-delete-button {
    padding: 5px 10px;

    border:
        1px solid rgba(255,80,80,.40);

    border-radius: 6px;

    background: transparent;

    color: #ff8080;

    font-size: 12px;
    font-weight: 700;

    cursor: pointer;
}


.review-delete-button:hover {
    background:
        rgba(255,80,80,.12);

    border-color:
        #ff6060;

    color:
        #ff6060;
}


/* =========================================================
 * 리뷰 없음
 * ========================================================= */

.review-empty {
    padding: 8px 0 14px;

    color:
        rgba(255,255,255,.55);
}


/* =========================================================
 * 의견 남기기
 * ========================================================= */

.review-write {
    margin-top: 17px;

    padding-top: 15px;

    border-top:
        1px solid rgba(255,255,255,.10);
}


.review-write a {
    color: #19b8ff;

    font-weight: 700;

    text-decoration: none;
}


.review-write a:hover {
    text-decoration: underline;
}


/* =========================================================
 * 모바일
 * ========================================================= */

@media (max-width: 700px) {

    .project-actions {
        gap: 8px;
    }


    .review-reply {
        margin-left: 12px;
    }

}

</style>


<!-- =======================================================
     상단
     ======================================================= -->

<section class="subhero">

<div class="container">


    <p class="section-kicker">
        APPS
    </p>


    <h1>
        STIMPACK GAME LAB 앱
    </h1>


    <p>
        직접 기획하고 만들고 테스트하는
        앱 프로젝트를 소개합니다.
    </p>


    <!-- ===================================================
         관리자 / 로그인
         =================================================== -->

    <div class="app-admin-tools">


    <?php if ($isGameLabAdmin): ?>


        <span class="admin-login-badge">
            ✓ 관리자 로그인
        </span>


        <a
            class="button primary"
            href="/manage/add.php?type=app"
        >
            ＋ 앱 추가
        </a>


        <a
            class="button secondary"
            href="/auth/logout.php"
        >
            관리자 로그아웃
        </a>


    <?php elseif ($currentGoogleEmail !== ''): ?>


        <span class="button secondary">

            Google 로그인

        </span>


    <?php else: ?>


        <a
            class="button secondary"
            href="/auth/google_login.php"
        >

            Google 로그인

        </a>


    <?php endif; ?>


    <!-- ===================================================
         승인된 테스터
         =================================================== -->

    <?php if (
        $testerStatus === 'approved'
    ): ?>


        <span class="
            button
            tester-approved
        ">

            ✓ 테스터

        </span>


    <!-- ===================================================
         승인 대기
         =================================================== -->

    <?php elseif (
        $testerStatus === 'pending'
    ): ?>


        <span class="
            button
            tester-pending
        ">

            ⏳ 테스터 참여대기

        </span>


    <!-- ===================================================
         미승인
         =================================================== -->

    <?php elseif (
        $testerStatus === 'rejected'
    ): ?>


        <span class="
            button
            tester-rejected
        ">

            테스터 참여 미승인

        </span>


    <!-- ===================================================
         신규 신청 가능
         =================================================== -->

    <?php elseif (
        $canJoinTester
    ): ?>


        <form
            method="post"
            action="/tester/join.php"
            class="tester-join-form"
        >


            <input
                type="hidden"
                name="csrf"
                value="<?= e(
                    gamelab_csrf_token()
                ) ?>"
            >


            <button
                type="submit"
                class="
                    button
                    tester-join-button
                "
            >

                ＋ 테스터 참여

            </button>


        </form>


    <?php endif; ?>


</div>


</div>

</section>


<!-- =======================================================
     앱 목록
     ======================================================= -->

<section class="section">

<div class="container detail-stack">


<?php foreach ($apps as $app): ?>


    <?php

    /* =====================================================
     * 앱 ID
     *
     * Google Play 패키지 ID
     *
     * 예:
     * pe.kr.forum.medicalrecord
     * ===================================================== */

    $contentId = trim(
        (string)(
            $app['id']
            ?? ''
        )
    );


    /* =====================================================
     * 리뷰 개수
     * ===================================================== */

    $reviewCount = 0;

    if ($contentId !== '') {

        $reviewCount = review_count(
            'app',
            $contentId
        );
    }


    /* =====================================================
     * 리뷰 목록
     * ===================================================== */

    $reviews = [];

    if ($contentId !== '') {

        $reviews = review_list(
            'app',
            $contentId
        );
    }


    /* =====================================================
     * HTML ID
     * ===================================================== */

    $safeId = preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '-',
        $contentId
    );


    $descriptionId =
        'description-app-' .
        $safeId;


    $purposeId =
        'purpose-app-' .
        $safeId;


    $purpose = trim(
        (string)(
            $app['purpose']
            ?? ''
        )
    );


    $reviewPanelId =
        'review-app-' .
        $safeId;


    /* =====================================================
     * 리뷰 저장/삭제 후 자동 펼침
     * ===================================================== */

    $openThisReview =
        $openReviewId !== ''
        &&
        $contentId !== ''
        &&
        hash_equals(
            $contentId,
            $openReviewId
        );


    /* =====================================================
     * 이미지 캐시 자동 무효화
     *
     * 같은 이름으로 이미지 교체해도
     * 새 이미지 즉시 표시
     * ===================================================== */

    $imageUrl = trim(
        (string)(
            $app['image']
            ?? ''
        )
    );


    $imageSrc =
        $imageUrl;


    if (
        $imageUrl !== ''
        &&
        str_starts_with(
            $imageUrl,
            '/'
        )
    ) {

        $imageFile =
            dirname(__DIR__)
            . $imageUrl;


        if (is_file($imageFile)) {

            $imageSrc =
                $imageUrl
                . '?v='
                . filemtime($imageFile);
        }
    }


    /* =====================================================
     * Google Play URL
     * ===================================================== */

    $playUrl = trim(
        (string)(
            $app['url']
            ?? ''
        )
    );

    ?>


    <article
        id="<?= e(
            $contentId ?: 'app'
        ) ?>"
        class="project-row"
    >


        <!-- =================================================
             대표 이미지
             ================================================= -->

        <img
            src="<?= e($imageSrc) ?>"
            alt="<?= e(
                (string)(
                    $app['title']
                    ?? ''
                )
            ) ?>"
            loading="lazy"
        >


        <div class="project-body">


            <!-- =============================================
                 상태 뱃지
                 ============================================= -->

            <div class="app-status-badges">


                <span class="mini-status">

                    <?= e(
                        (string)(
                            $app['status']
                            ?? '개발중'
                        )
                    ) ?>

                </span>


                <!-- 출시 여부는 개발상태와 별개 -->

                <?php if (
                    !empty(
                        $app['released']
                    )
                ): ?>


                    <span
                        class="
                            mini-status
                            release-status
                        "
                    >

                        출시

                    </span>


                <?php endif; ?>


            </div>


            <!-- =============================================
                 제목
                 ============================================= -->

            <h2>

                <?= e(
                    (string)(
                        $app['title']
                        ?? ''
                    )
                ) ?>

            </h2>


            <?php if ($purpose !== ''): ?>

                <div
                    id="<?= e($purposeId) ?>"
                    class="project-purpose-panel"
                    hidden
                >
                    <strong>제작 의도</strong>

                    <?= nl2br(
                        e($purpose)
                    ) ?>
                </div>

            <?php endif; ?>


            <!-- =============================================
                 설명
                 
                 같은 설명 영역을
                 2줄 → 전체로 펼침
                 ============================================= -->

            <div
                id="<?= e(
                    $descriptionId
                ) ?>"
                class="project-description"
            >

                <?= nl2br(
                    e(
                        (string)(
                            $app['description']
                            ?? ''
                        )
                    )
                ) ?>

            </div>


            <!-- =============================================
                 버튼 영역
                 ============================================= -->

            <div class="project-actions">


                <?php if ($purpose !== ''): ?>

                    <button
                        type="button"
                        class="
                            button
                            secondary
                            purpose-toggle
                        "
                        data-purpose-target="<?= e($purposeId) ?>"
                    >
                        제작 의도
                    </button>

                <?php endif; ?>


                <!-- 자세히 보기 -->

                <button
                    type="button"
                    class="
                        button
                        secondary
                        detail-toggle
                    "
                    data-target="<?= e(
                        $descriptionId
                    ) ?>"
                >

                    자세히 보기

                </button>


                <!-- =========================================
                     Google Play
                     ========================================= -->

                <?php if (
                    $playUrl !== ''
                ): ?>


                    <a
                        class="
                            button
                            secondary
                            google-play-button
                        "
                        href="<?= e(
                            $playUrl
                        ) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                    >

                        Google Play

                    </a>


                <?php endif; ?>


                <!-- =========================================
                     리뷰
                     ========================================= -->

                <?php if (
                    $contentId !== ''
                ): ?>


                    <button
                        type="button"
                        class="
                            button
                            secondary
                            review-button
                            review-toggle
                        "
                        data-review-target="<?= e(
                            $reviewPanelId
                        ) ?>"
                    >

                        💬 리뷰 · 의견
                        <?= $reviewCount ?>

                    </button>


                <?php endif; ?>


                <!-- =========================================
                     관리자 수정
                     ========================================= -->

                <?php if (
                    $isGameLabAdmin
                    &&
                    $contentId !== ''
                ): ?>


                    <a
                        class="
                            button
                            secondary
                            app-edit-button
                        "
                        href="/manage/edit.php?type=app&id=<?= urlencode($contentId) ?>"
                    >

                        ✎ 수정

                    </a>


                <?php endif; ?>


            </div>


            <!-- =============================================
                 리뷰 패널
                 ============================================= -->

            <?php if (
                $contentId !== ''
            ): ?>


                <div
                    id="<?= e(
                        $reviewPanelId
                    ) ?>"
                    class="review-panel"
                    <?= $openThisReview
                        ? ''
                        : 'hidden'
                    ?>
                >


                    <div class="review-panel-title">

                        사용후기

                    </div>


                    <?php if (
                        empty($reviews)
                    ): ?>


                        <div class="review-empty">

                            아직 등록된 의견이 없습니다.
                            <br>

                            첫 의견을 남겨보세요.

                        </div>


                    <?php else: ?>


                        <?php foreach (
                            $reviews
                            as $review
                        ): ?>


                            <?php

                            $reviewId =
                                (int)(
                                    $review['id']
                                    ?? 0
                                );


                            $parentId =
                                (int)(
                                    $review['parent_id']
                                    ?? 0
                                );


                            $isReply =
                                $parentId > 0;


                            $isAdminReview =
                                (int)(
                                    $review['is_admin']
                                    ?? 0
                                ) === 1;


                            $reviewGoogleSub =
                                trim(
                                    (string)(
                                        $review[
                                            'google_sub'
                                        ]
                                        ?? ''
                                    )
                                );


                            /*
                             * 삭제 권한
                             *
                             * 관리자:
                             * 모두 삭제 가능
                             *
                             * 일반 Google 사용자:
                             * 자기 글만
                             */

                            $canDeleteReview =
                                $isGameLabAdmin
                                ||
                                (
                                    $currentGoogleSub !== ''
                                    &&
                                    $reviewGoogleSub !== ''
                                    &&
                                    hash_equals(
                                        $reviewGoogleSub,
                                        $currentGoogleSub
                                    )
                                );

                            ?>


                            <div
                                class="
                                    review-item
                                    <?= $isReply
                                        ? 'review-reply'
                                        : ''
                                    ?>
                                "
                            >


                                <!-- 작성자 -->

                                <div
                                    class="
                                        review-author
                                        <?= $isAdminReview
                                            ? 'admin'
                                            : ''
                                        ?>
                                    "
                                >


                                    <?php if (
                                        $isAdminReview
                                    ): ?>


                                        ↳ 개발자


                                    <?php else: ?>


                                        <?= e(
                                            (string)(
                                                $review[
                                                    'user_name'
                                                ]
                                                ?? '사용자'
                                            )
                                        ) ?>


                                    <?php endif; ?>


                                </div>


                                <!-- 내용 -->

                                <div class="review-content">

                                    <?= nl2br(
                                        e(
                                            (string)(
                                                $review[
                                                    'content'
                                                ]
                                                ?? ''
                                            )
                                        )
                                    ) ?>

                                </div>


                                <!-- 날짜 -->

                                <?php if (
                                    !empty(
                                        $review[
                                            'created_at'
                                        ]
                                    )
                                ): ?>


                                    <div class="review-date">

                                        <?= e(
                                            (string)
                                            $review[
                                                'created_at'
                                            ]
                                        ) ?>

                                    </div>


                                <?php endif; ?>


                                <!-- 답글 / 삭제 -->

                                <div class="review-tools">


                                    <!-- 관리자 답글 -->

                                    <?php if (
                                        $isGameLabAdmin
                                        &&
                                        !$isReply
                                    ): ?>


                                        <a
                                            class="review-reply-link"
                                            href="/reviews/write.php?type=app&id=<?= urlencode($contentId) ?>&parent_id=<?= $reviewId ?>"
                                        >

                                            답글

                                        </a>


                                    <?php endif; ?>


                                    <!-- 삭제 -->

                                    <?php if (
                                        $canDeleteReview
                                    ): ?>


                                        <form
                                            method="post"
                                            action="/reviews/delete.php"
                                            class="review-delete-form"
                                            onsubmit="
                                                return confirm(
                                                    '이 의견을 삭제할까요?'
                                                );
                                            "
                                        >


                                            <input
                                                type="hidden"
                                                name="review_id"
                                                value="<?= $reviewId ?>"
                                            >


                                            <input
                                                type="hidden"
                                                name="type"
                                                value="app"
                                            >


                                            <input
                                                type="hidden"
                                                name="content_id"
                                                value="<?= e(
                                                    $contentId
                                                ) ?>"
                                            >


                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= e(
                                                    gamelab_csrf_token()
                                                ) ?>"
                                            >


                                            <button
                                                type="submit"
                                                class="
                                                    review-delete-button
                                                "
                                            >

                                                삭제

                                            </button>


                                        </form>


                                    <?php endif; ?>


                                </div>


                            </div>


                        <?php endforeach; ?>


                    <?php endif; ?>


                    <!-- =====================================
                         의견 작성
                         ===================================== -->

                    <div class="review-write">


                        <a
                            href="/reviews/write.php?type=app&id=<?= urlencode($contentId) ?>"
                        >

                            [의견 남기기]

                        </a>


                    </div>


                </div>


            <?php endif; ?>


        </div>


    </article>


<?php endforeach; ?>


</div>

</section>


<script>

/* =========================================================
 * 제작 의도 펼치기 / 접기
 * ========================================================= */

document.addEventListener(
    'click',
    function (event) {

        const button =
            event.target.closest(
                '.purpose-toggle'
            );

        if (!button) {
            return;
        }

        const targetId =
            button.dataset.purposeTarget;

        if (!targetId) {
            return;
        }

        const panel =
            document.getElementById(
                targetId
            );

        if (!panel) {
            return;
        }

        panel.hidden = !panel.hidden;

        button.textContent =
            panel.hidden
                ? '제작 의도'
                : '제작 의도 닫기';
    }
);


/* =========================================================
 * 자세히 보기
 *
 * 내용을 하나 더 만들지 않고
 * 기존 설명 자체를 펼침
 * ========================================================= */

document.addEventListener(
    'click',
    function (event) {

        const button =
            event.target.closest(
                '.detail-toggle'
            );


        if (!button) {
            return;
        }


        const targetId =
            button.dataset.target;


        if (!targetId) {
            return;
        }


        const description =
            document.getElementById(
                targetId
            );


        if (!description) {
            return;
        }


        const expanded =
            description.classList.toggle(
                'expanded'
            );


        button.textContent =
            expanded
                ? '내용 접기'
                : '자세히 보기';
    }
);


/* =========================================================
 * 리뷰 펼치기 / 접기
 * ========================================================= */

document.addEventListener(
    'click',
    function (event) {

        const button =
            event.target.closest(
                '.review-toggle'
            );


        if (!button) {
            return;
        }


        const targetId =
            button.dataset.reviewTarget;


        if (!targetId) {
            return;
        }


        const panel =
            document.getElementById(
                targetId
            );


        if (!panel) {
            return;
        }


        panel.hidden =
            !panel.hidden;
    }
);

</script>


<?php

require dirname(__DIR__)
    . '/includes/footer.php';

?>