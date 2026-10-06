<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';


/* =========================================================
 * SESSION
 * ========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* =========================================================
 * Google 로그인 사용자 확인
 * ========================================================= */

$loginEmail = '';
$loginName  = '';
$loginPicture = '';
$googleSub = '';

if (
    isset($_SESSION['google_user'])
    &&
    is_array($_SESSION['google_user'])
) {

    $googleSub =
        trim(
            (string)(
                $_SESSION['google_user']['sub']
                ?? ''
            )
        );

    $loginEmail =
        strtolower(
            trim(
                (string)(
                    $_SESSION['google_user']['email']
                    ?? ''
                )
            )
        );

    $loginName =
        trim(
            (string)(
                $_SESSION['google_user']['name']
                ?? ''
            )
        );

    $loginPicture =
        trim(
            (string)(
                $_SESSION['google_user']['picture']
                ?? ''
            )
        );
}


/*
 * 기존 세션 호환
 */

if (
    $loginEmail === ''
    &&
    !empty($_SESSION['google_email'])
) {

    $loginEmail =
        strtolower(
            trim(
                (string)$_SESSION['google_email']
            )
        );
}


/* =========================================================
 * 로그인 여부
 * ========================================================= */

$isGoogleLoggedIn =
    $loginEmail !== '';


/* =========================================================
 * 관리자 여부
 *
 * 로그인 상태와 관리자 상태를 절대 혼동하지 않는다.
 * ========================================================= */

$isGameLabAdmin =
    (
        $_SESSION['gamelab_admin']
        ?? false
    ) === true;


/*
 * 기존 세션과의 호환용 관리자 보정
 */

if (
    !$isGameLabAdmin
    &&
    $loginEmail !== ''
    &&
    strcasecmp(
        $loginEmail,
        'esotarsound@gmail.com'
    ) === 0
) {

    $isGameLabAdmin = true;

    $_SESSION['gamelab_admin'] = true;
}


/* =========================================================
 * 화면 표시용 사용자명
 * ========================================================= */

$displayName = '';

if ($loginName !== '') {

    $displayName = $loginName;

} elseif ($loginEmail !== '') {

    $displayName = $loginEmail;
}


/* =========================================================
 * PAGE TITLE
 * ========================================================= */

$pageTitle =
    $pageTitle
    ?? SITE_NAME;


$fullTitle =
    $pageTitle === SITE_NAME
        ? SITE_NAME
        : $pageTitle . ' | ' . SITE_NAME;

?>
<!doctype html>
<html lang="ko">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="description"
        content="<?= e(SITE_DESCRIPTION) ?>"
    >

    <meta
        name="theme-color"
        content="#071524"
    >

    <title><?= e($fullTitle) ?></title>


    <link
        rel="stylesheet"
        href="/assets/css/style.css"
    >


    <script
        src="/assets/js/app.js"
        defer
    ></script>


    <!-- Google AdSense -->

    <script
        async
        src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3505785580107470"
        crossorigin="anonymous">
    </script>

</head>


<body>


<header class="site-header">

    <div class="container header-inner">


        <!-- =================================================
             LOGO
             ================================================= -->

        <a
            class="brand"
            href="/"
            aria-label="STIMPACK GAME LAB 홈"
        >

            <span class="brand-mark">
                🎮
            </span>

            <span class="brand-name">
                <em>STIMPACK</em>
                GAME
                <b>LAB</b>
            </span>

            <span class="brand-sub">
                SINCE 2012 · PLAY · DEVELOP · TEST
            </span>

        </a>


        <!-- =================================================
             모바일 메뉴
             ================================================= -->

        <button
            class="mobile-menu-button"
            type="button"
            aria-expanded="false"
            aria-controls="main-nav"
        >
            ☰
        </button>


        <!-- =================================================
             MAIN NAVIGATION
             ================================================= -->

        <nav
            id="main-nav"
            class="main-nav"
            aria-label="주 메뉴"
        >

            <a
                class="<?= is_active('/') ? 'active' : '' ?>"
                href="/"
            >
                홈
            </a>


            <a
                class="<?= is_active('/games') ? 'active' : '' ?>"
                href="/games/"
            >
                웹게임
            </a>


            <a
                class="<?= is_active('/apps') ? 'active' : '' ?>"
                href="/apps/"
            >
                앱
            </a>


            <a
                class="<?= is_active('/test') ? 'active' : '' ?>"
                href="/test/"
            >
                개발테스트
            </a>


            <a href="<?= e(WOW_URL) ?>">
                WOW
            </a>


            <a
                class="<?= is_active('/devlog') ? 'active' : '' ?>"
                href="/devlog/"
            >
                개발일지
            </a>


            <a
                class="<?= is_active('/community') ? 'active' : '' ?>"
                href="/community/"
            >
                토론
            </a>


            <!-- =============================================
                 로그인 상태
                 ============================================= -->

            <?php if ($isGoogleLoggedIn): ?>


                <?php if ($isGameLabAdmin): ?>

                    <span
                        class="google-user"
                        title="<?= e($loginEmail) ?>"
                    >
                        관리자
                    </span>

                <?php else: ?>

                    <span
                        class="google-user"
                        title="<?= e($loginEmail) ?>"
                    >
                        <?= e($displayName) ?>
                    </span>

                <?php endif; ?>


                <a
                    href="/auth/logout.php"
                    class="google-login"
                >
                    로그아웃
                </a>


            <?php else: ?>


                <a
                    href="/auth/google_login.php"
                    class="google-login"
                >
                    Google 로그인
                </a>


            <?php endif; ?>


        </nav>

    </div>

</header>


<main>