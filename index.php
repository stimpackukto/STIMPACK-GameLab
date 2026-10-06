<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/data.php';


/* =========================================================
 * GAME LAB 로그인 / 관리자 확인
 * ========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


const GAMELAB_ADMIN_EMAIL = 'esotarsound@gmail.com';


$loginEmail = '';


if (!empty($_SESSION['google_user']['email'])) {

    $loginEmail =
        (string)$_SESSION['google_user']['email'];

} elseif (!empty($_SESSION['user']['email'])) {

    $loginEmail =
        (string)$_SESSION['user']['email'];

} elseif (!empty($_SESSION['google_email'])) {

    $loginEmail =
        (string)$_SESSION['google_email'];

} elseif (!empty($_SESSION['email'])) {

    $loginEmail =
        (string)$_SESSION['email'];
}


$isGameLabAdmin =
    $loginEmail !== ''
    &&
    strcasecmp(
        trim($loginEmail),
        GAMELAB_ADMIN_EMAIL
    ) === 0;


$pageTitle = SITE_NAME;

require __DIR__ . '/includes/header.php';
?>


<style>

/* =========================================================
 * HOME - GAME / APP GRID
 * ========================================================= */

.home-game-grid,
.image-card-grid.three.app-cards {

    display: grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 20px;

    align-items: stretch;
}


/* =========================================================
 * HOME GAME CARD
 * ========================================================= */

.home-game-card {

    display: flex;

    flex-direction: column;

    min-width: 0;

    height: 100%;

    overflow: hidden;

    border:
        1px solid rgba(44,139,196,.38);

    border-radius: 16px;

    background:
        rgba(4,30,49,.68);
}


/* =========================================================
 * GAME IMAGE
 *
 * 항상 16:9
 * ========================================================= */

.home-game-image {

    display: block;

    width: 100% !important;

    height: auto !important;

    aspect-ratio: 16 / 9;

    object-fit: cover;

    object-position: center;

    flex: 0 0 auto;
}


/* =========================================================
 * GAME BODY
 * ========================================================= */

.home-game-copy {

    display: flex;

    flex-direction: column;

    flex: 1;

    min-width: 0;

    padding: 20px;
}


/* =========================================================
 * GAME BADGES
 * ========================================================= */

.home-game-badges {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: 7px;

    margin-bottom: 14px;
}


.home-game-badges span {

    display: inline-flex;

    align-items: center;

    padding: 6px 11px;

    border-radius: 999px;

    background:
        rgba(14,165,233,.15);

    color: #7dd3fc;

    font-size: 13px;

    font-weight: 800;
}


.home-game-badges .status {

    background: #0ea5e9;

    color: #fff;
}


/* =========================================================
 * GAME TITLE
 *
 * 최대 2줄
 * ========================================================= */

.home-game-title {

    margin:
        0 0 12px;

    line-height: 1.4;

    display: -webkit-box;

    -webkit-box-orient: vertical;

    -webkit-line-clamp: 2;

    overflow: hidden;
}


/* =========================================================
 * GAME DESCRIPTION
 *
 * 최대 4줄
 * ========================================================= */

.home-game-description {

    margin:
        0 0 18px;

    color: #c9d8ea;

    line-height: 1.7;

    display: -webkit-box;

    -webkit-box-orient: vertical;

    -webkit-line-clamp: 4;

    overflow: hidden;

    text-overflow: ellipsis;
}


/* =========================================================
 * GAME ACTION
 * ========================================================= */

.home-game-action {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    align-self: flex-start;

    margin-top: auto;

    padding: 11px 16px;

    border-radius: 10px;

    background: #0ea5e9;

    color: #fff;

    font-weight: 800;

    text-decoration: none;
}


.home-game-action:hover {

    filter: brightness(1.08);
}


/* =========================================================
 * APP CARD
 * ========================================================= */

.app-cards .app-card {

    display: flex !important;

    flex-direction: column;

    min-width: 0;

    height: 100%;

    overflow: hidden;

    border:
        1px solid rgba(44,139,196,.38);

    border-radius: 16px;

    background:
        rgba(4,30,49,.68);
}


/* =========================================================
 * APP IMAGE
 *
 * 항상 16:9
 * ========================================================= */

.app-cards .app-card-image {

    display: block;

    width: 100% !important;

    height: auto !important;

    aspect-ratio: 16 / 9;

    object-fit: cover;

    object-position: center;

    flex: 0 0 auto;
}


/* =========================================================
 * APP BODY
 * ========================================================= */

.app-cards .app-copy {

    display: flex;

    flex-direction: column;

    flex: 1;

    min-width: 0;

    padding: 20px;
}


/* =========================================================
 * APP STATUS
 * ========================================================= */

.app-cards .app-status-row {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: 7px;

    margin-bottom: 14px;
}


.app-cards .app-status-row .mini-status {

    margin: 0;
}


.app-cards .release-status {

    background: #22c55e !important;

    color: #fff !important;
}


/* =========================================================
 * APP TITLE
 *
 * 최대 2줄
 * ========================================================= */

.app-cards .app-card-title {

    margin:
        0 0 12px;

    line-height: 1.4;

    display: -webkit-box;

    -webkit-box-orient: vertical;

    -webkit-line-clamp: 2;

    overflow: hidden;
}


/* =========================================================
 * APP DESCRIPTION
 *
 * 최대 4줄
 * ========================================================= */

.app-cards .app-card-description {

    margin:
        0 0 18px;

    color: #c9d8ea;

    line-height: 1.7;

    display: -webkit-box;

    -webkit-box-orient: vertical;

    -webkit-line-clamp: 4;

    overflow: hidden;

    text-overflow: ellipsis;
}


/* =========================================================
 * APP DETAIL
 * ========================================================= */

.app-cards .app-card-more {

    display: inline-flex;

    align-items: center;

    align-self: flex-start;

    margin-top: auto;

    color: #19b8ff;

    font-weight: 800;

    text-decoration: none;
}


.app-cards .app-card-more:hover {

    text-decoration: underline;
}


/* =========================================================
 * TABLET
 * ========================================================= */

@media (max-width: 1050px) {

    .home-game-grid,
    .image-card-grid.three.app-cards {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

}


/* =========================================================
 * MOBILE
 * ========================================================= */

@media (max-width: 700px) {

    .home-game-grid,
    .image-card-grid.three.app-cards {

        grid-template-columns: 1fr;
    }


    .home-game-copy,
    .app-cards .app-copy {

        padding: 18px;
    }

}

</style>


<!-- =========================================================
     WOW HERO

     로그인 여부와 관계없이 모두 공개
     ========================================================= -->

<section class="hero wow-feature-hero">

    <div
        class="hero-bg"
        aria-hidden="true">
    </div>


    <div
        class="hero-overlay"
        aria-hidden="true">
    </div>


    <div class="container hero-content">


        <div class="hero-copy">


            <div class="hero-badges">


                <span class="hero-badge live">

                    ● LIVE DEVELOPMENT

                </span>


                <span class="hero-badge">

                    WOTLK 3.3.5a

                </span>


            </div>


            <p class="eyebrow">

                STIMPACK GAME LAB · FEATURED PROJECT

            </p>


            <h1 class="wow-main-title">

                WOW

                <span>

                    DEV SERVER

                </span>

            </h1>


            <h2 class="wow-project-name">

                빛의수호자

            </h2>


            <p class="hero-description">

                리치왕의 분노 3.3.5a 기반으로 직접 개발하고 운영하는
                STIMPACK GAME LAB의 대표 MMORPG 개발 서버입니다.
                유저의 참여와 피드백을 바탕으로 지속적으로 콘텐츠를 개발합니다.

            </p>


            <div class="wow-meta">


                <span>

                    ⚡ 현재 운영중

                </span>


                <span>

                    🛠 직접 개발 · 운영

                </span>


                <span>

                    👥 유저 참여형 개발

                </span>


            </div>


            <div class="hero-actions">


                <a
                    class="button primary wow-enter"
                    href="<?= e(WOW_URL) ?>"
                >

                    ▶ WOW 바로가기

                </a>


                <a
                    class="button secondary"
                    href="/wow/auth/register"
                >

                    계정 생성

                </a>


                <a
                    class="button secondary"
                    href="/wow/home/news"
                >

                    새소식

                </a>


            </div>


        </div>


    </div>

</section>


<!-- =========================================================
     GAME LAB 바로가기

     관리자 / 로그인 / 미로그인 모두 공개
     ========================================================= -->

<section class="portal-strip">

    <div class="container portal-grid">


        <a href="/games/">

            <span>
                🎮
            </span>

            <b>
                웹게임
            </b>

            <small>
                바로 즐기는 게임
            </small>

        </a>


        <a href="/apps/">

            <span>
                📱
            </span>

            <b>
                앱
            </b>

            <small>
                직접 만든 모바일 앱
            </small>

        </a>


        <a href="/test/">

            <span>
                🧪
            </span>

            <b>
                개발테스트
            </b>

            <small>
                비공개 테스트 참여
            </small>

        </a>


        <a href="<?= e(WOW_URL) ?>">

            <span>
                W
            </span>

            <b>
                WOW 개발 서버
            </b>

            <small>
                현재 운영 중
            </small>

        </a>


        <a href="/community/">

            <span>
                💬
            </span>

            <b>
                개발자 공간
            </b>

            <small>
                개발 이야기와 토론
            </small>

        </a>


    </div>

</section>


<!-- =========================================================
     지금 플레이

     모두 공개
     ========================================================= -->

<section
    id="play"
    class="section"
>

    <div class="container">


        <div class="section-heading">


            <div>


                <p class="section-kicker">

                    PLAY NOW

                </p>


                <h2>

                    지금 플레이

                </h2>


                <p>

                    GAME LAB에서 현재 만나볼 수 있는 게임과 서버입니다.

                </p>


            </div>


            <a href="/games/">

                전체보기 →

            </a>


        </div>


        <div class="home-game-grid">


            <?php foreach ($games as $game): ?>


                <?php

                /* =============================================
                 * 대표 이미지 캐시 갱신
                 * ============================================= */

                $gameImageUrl = trim(
                    (string)(
                        $game['image']
                        ?? ''
                    )
                );


                $gameImageSrc =
                    $gameImageUrl;


                if (
                    $gameImageUrl !== ''
                    &&
                    str_starts_with(
                        $gameImageUrl,
                        '/'
                    )
                ) {

                    $gameImageFile =
                        __DIR__
                        . $gameImageUrl;


                    if (
                        is_file(
                            $gameImageFile
                        )
                    ) {

                        $gameImageSrc =
                            $gameImageUrl
                            . '?v='
                            . filemtime(
                                $gameImageFile
                            );
                    }
                }

                ?>


                <article class="home-game-card">


                    <img
                        class="home-game-image"
                        src="<?= e($gameImageSrc) ?>"
                        alt="<?= e(
                            (string)(
                                $game['title']
                                ?? ''
                            )
                        ) ?>"
                        loading="lazy"
                    >


                    <div class="home-game-copy">


                        <div class="home-game-badges">


                            <?php if (
                                !empty(
                                    $game['badge']
                                )
                            ): ?>


                                <span>

                                    <?= e(
                                        (string)$game[
                                            'badge'
                                        ]
                                    ) ?>

                                </span>


                            <?php endif; ?>


                            <span class="status">

                                <?= e(
                                    (string)(
                                        $game['status']
                                        ?? '개발중'
                                    )
                                ) ?>

                            </span>


                        </div>


                        <h3 class="home-game-title">

                            <?= e(
                                (string)(
                                    $game['title']
                                    ?? ''
                                )
                            ) ?>

                        </h3>


                        <p class="home-game-description">

                            <?= e(
                                (string)(
                                    $game['description']
                                    ?? ''
                                )
                            ) ?>

                        </p>


                        <?php if (
                            !empty(
                                $game['url']
                            )
                        ): ?>


                            <a
                                class="home-game-action"
                                href="<?= e(
                                    (string)$game[
                                        'url'
                                    ]
                                ) ?>"
                                <?= !empty(
                                    $game['external']
                                )
                                    ? 'target="_blank" rel="noopener noreferrer"'
                                    : ''
                                ?>
                            >

                                <?= e(
                                    (string)(
                                        $game['action']
                                        ?? '게임하기'
                                    )
                                ) ?> →

                            </a>


                        <?php endif; ?>


                    </div>


                </article>


            <?php endforeach; ?>


        </div>


    </div>

</section>


<!-- =========================================================
     GAME LAB APPS

     모두 공개
     ========================================================= -->

<section class="section section-muted">

    <div class="container">


        <div class="section-heading">


            <div>


                <p class="section-kicker">

                    STIMPACK GAME LAB APPS

                </p>


                <h2>

                    STIMPACK GAME LAB 앱

                </h2>


                <p>

                    직접 만들고 테스트하는 생활·도구 앱을 소개합니다.

                </p>


            </div>


            <a href="/apps/">

                전체보기 →

            </a>


        </div>


        <div class="image-card-grid three app-cards">


            <?php foreach ($apps as $app): ?>


                <?php

                /* =============================================
                 * APP 대표 이미지
                 * ============================================= */

                $appImageUrl = trim(
                    (string)(
                        $app['image']
                        ?? ''
                    )
                );


                $appImageSrc =
                    $appImageUrl;


                if (
                    $appImageUrl !== ''
                    &&
                    str_starts_with(
                        $appImageUrl,
                        '/'
                    )
                ) {

                    $appImageFile =
                        __DIR__
                        . $appImageUrl;


                    if (
                        is_file(
                            $appImageFile
                        )
                    ) {

                        $appImageSrc =
                            $appImageUrl
                            . '?v='
                            . filemtime(
                                $appImageFile
                            );
                    }
                }


                $appId = trim(
                    (string)(
                        $app['id']
                        ?? ''
                    )
                );

                ?>


                <article class="app-card">


                    <img
                        class="app-card-image"
                        src="<?= e($appImageSrc) ?>"
                        alt="<?= e(
                            (string)(
                                $app['title']
                                ?? ''
                            )
                        ) ?>"
                        loading="lazy"
                    >


                    <div class="app-copy">


                        <div class="app-status-row">


                            <span class="mini-status">

                                <?= e(
                                    (string)(
                                        $app['status']
                                        ?? '개발중'
                                    )
                                ) ?>

                            </span>


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


                        <h3 class="app-card-title">

                            <?= e(
                                (string)(
                                    $app['title']
                                    ?? ''
                                )
                            ) ?>

                        </h3>


                        <p class="app-card-description">

                            <?= e(
                                (string)(
                                    $app['description']
                                    ?? ''
                                )
                            ) ?>

                        </p>


                        <a
                            class="app-card-more"
                            href="/apps/#<?= e($appId) ?>"
                        >

                            자세히 보기 →

                        </a>


                    </div>


                </article>


            <?php endforeach; ?>


        </div>


    </div>

</section>


<!-- =========================================================
     비공개 개발테스트

     모두 공개
     ========================================================= -->

<section class="section">

    <div class="container">


        <div class="section-heading">


            <div>


                <p class="section-kicker">

                    PRIVATE TEST

                </p>


                <h2>

                    비공개 개발테스트

                </h2>


                <p>

                    새로운 앱과 게임을 정식 공개 전에
                    먼저 경험해보세요.

                </p>


            </div>


            <a href="/test/">

                테스트 페이지 →

            </a>


        </div>


        <div class="simple-card-grid three">


            <a
                class="simple-card"
                href="/test/"
            >


                <span class="simple-icon">

                    👥

                </span>


                <div>


                    <b>

                        테스터 모집

                    </b>


                    <p>

                        현재 모집 중인 테스트 프로젝트

                    </p>


                </div>


                <strong>

                    →

                </strong>


            </a>


            <a
                class="simple-card"
                href="/test/"
            >


                <span class="simple-icon">

                    📲

                </span>


                <div>


                    <b>

                        참여 가능한 앱

                    </b>


                    <p>

                        지금 참여할 수 있는 비공개 테스트

                    </p>


                </div>


                <strong>

                    →

                </strong>


            </a>


            <a
                class="simple-card"
                href="/test/"
            >


                <span class="simple-icon">

                    ✅

                </span>


                <div>


                    <b>

                        완료된 테스트

                    </b>


                    <p>

                        지난 테스트 결과와 업데이트 기록

                    </p>


                </div>


                <strong>

                    →

                </strong>


            </a>


        </div>


    </div>

</section>


<!-- =========================================================
     COMMUNITY / DEV LOG

     모두 공개
     ========================================================= -->

<section class="section section-muted">

    <div class="container split-grid">


        <!-- COMMUNITY -->

        <div>


            <div class="section-heading compact">


                <div>


                    <p class="section-kicker">

                        COMMUNITY

                    </p>


                    <h2>

                        개발자 토론

                    </h2>


                </div>


                <a href="/community/">

                    전체보기 →

                </a>


            </div>


            <div class="text-list">


                <?php foreach ($communityPosts as $post): ?>


                    <a href="/community/">


                        <span class="list-tag">

                            <?= e($post['category']) ?>

                        </span>


                        <b>

                            <?= e($post['title']) ?>

                        </b>


                        <small>

                            <?= e($post['meta']) ?>

                        </small>


                    </a>


                <?php endforeach; ?>


            </div>


        </div>


        <!-- DEV LOG -->

        <div>


            <div class="section-heading compact">


                <div>


                    <p class="section-kicker">

                        DEV LOG

                    </p>


                    <h2>

                        최근 개발일지

                    </h2>


                </div>


                <a href="/devlog/">

                    전체보기 →

                </a>


            </div>


            <div class="text-list">


                <?php foreach ($devlogs as $log): ?>


                    <a href="/devlog/">


                        <span class="list-tag">

                            <?= e($log['tag']) ?>

                        </span>


                        <b>

                            <?= e($log['title']) ?>

                        </b>


                        <small>

                            <?= e($log['date']) ?>

                        </small>


                    </a>


                <?php endforeach; ?>


            </div>


        </div>


    </div>

</section>


<?php

require __DIR__ . '/includes/footer.php';

?>