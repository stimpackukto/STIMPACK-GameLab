<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/catalog.php';


/* =========================================================
 * 관리자만 접근
 * ========================================================= */

gamelab_require_admin();

$isGameLabAdmin = true;


/* =========================================================
 * 등록 종류
 *
 * app
 * game
 * ========================================================= */

$type = trim(
    (string)(
        $_POST['type']
        ?? $_GET['type']
        ?? ''
    )
);

if (!in_array($type, ['app', 'game'], true)) {

    http_response_code(400);

    exit('잘못된 등록 종류입니다.');
}

$isGame = ($type === 'game');


$pageTitle =
    $isGame
        ? '게임 추가'
        : '앱 추가';


$error = '';


/* =========================================================
 * 입력값
 *
 * 저장 실패 시 입력 내용 유지
 * ========================================================= */

$formTitle = trim(
    (string)(
        $_POST['title']
        ?? ''
    )
);


$formPurpose = trim(
    (string)(
        $_POST['purpose']
        ?? ''
    )
);


$formDescription = trim(
    (string)(
        $_POST['description']
        ?? ''
    )
);


$formStatus = trim(
    (string)(
        $_POST['status']
        ?? '개발중'
    )
);


$formBadge = trim(
    (string)(
        $_POST['badge']
        ?? '웹게임'
    )
);


/*
 * APP:
 * Google Play URL
 *
 * GAME:
 * 게임 실행 URL
 */

$formUrl = trim(
    (string)(
        $_POST['url']
        ?? ''
    )
);


/*
 * GAME 전용
 *
 * Android 버전이 출시된 경우
 * Google Play URL
 */

$formPlayUrl = trim(
    (string)(
        $_POST['play_url']
        ?? ''
    )
);


/*
 * 기존 이미지 직접 지정용
 */

$formImagePath = trim(
    (string)(
        $_POST['image_path']
        ?? ''
    )
);


/*
 * APP 전용
 *
 * 개발상태와 별개로
 * Google Play 출시 여부
 *
 * 예:
 *
 * status   = 개발중
 * released = true
 *
 * 화면:
 * [개발중] [출시]
 */

$formReleased =
    !$isGame
    &&
    !empty($_POST['released']);


/* =========================================================
 * 저장
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /* =================================================
         * CSRF
         * ================================================= */

        $csrf = (string)(
            $_POST['csrf']
            ?? ''
        );


        if (!gamelab_verify_csrf($csrf)) {

            throw new RuntimeException(
                '잘못된 요청입니다. 다시 시도해주세요.'
            );
        }


        /* =================================================
         * 제목
         * ================================================= */

        if ($formTitle === '') {

            throw new RuntimeException(
                '제목을 입력해주세요.'
            );
        }


        /* =================================================
         * 설명
         * ================================================= */

        if ($formDescription === '') {

            throw new RuntimeException(
                '설명을 입력해주세요.'
            );
        }


        /* =================================================
         * 상태
         * ================================================= */

        if ($formStatus === '') {

            $formStatus = '개발중';
        }


        /* =================================================
         * 기본 URL
         *
         * APP:
         * Google Play 주소
         *
         * GAME:
         * /games/crimescene/
         *
         * 이 값으로 콘텐츠 ID도 생성하므로 필수
         * ================================================= */

        if ($formUrl === '') {

            throw new RuntimeException(
                $isGame
                    ? '게임 실행 URL을 입력해주세요.'
                    : 'Google Play URL을 입력해주세요.'
            );
        }


        if (!catalog_valid_url($formUrl)) {

            throw new RuntimeException(
                $isGame
                    ? '게임 실행 URL 형식이 올바르지 않습니다.'
                    : 'Google Play URL 형식이 올바르지 않습니다.'
            );
        }


        /* =================================================
         * GAME Google Play URL
         *
         * 선택사항
         * ================================================= */

        if (
            $isGame
            &&
            $formPlayUrl !== ''
            &&
            !catalog_valid_url($formPlayUrl)
        ) {

            throw new RuntimeException(
                '게임 Google Play URL 형식이 올바르지 않습니다.'
            );
        }


        /* =================================================
         * 콘텐츠 ID 자동 생성
         *
         * APP
         *
         * https://play.google.com/store/apps/details
         * ?id=pe.kr.forum.medicalrecord
         *
         * ↓
         *
         * pe.kr.forum.medicalrecord
         *
         *
         * GAME
         *
         * /games/crimescene/
         *
         * ↓
         *
         * crimescene
         * ================================================= */

        $id = catalog_image_name_from_url(
            $type,
            $formUrl
        );


        /* =================================================
         * 대표 이미지 업로드
         *
         * JPG / PNG / WEBP
         *
         * ↓
         *
         * 1280 × 720
         * WebP
         * 품질 88
         *
         *
         * APP
         *
         * /assets/images/apps/
         * pe.kr.forum.medicalrecord.webp
         *
         *
         * GAME
         *
         * /assets/images/games/
         * crimescene.webp
         * ================================================= */

        $image = catalog_upload_image(
            'image',
            $type,
            $formUrl
        );


        /* =================================================
         * 이미지 파일을 올리지 않고
         * 기존 이미지 경로를 사용하는 경우
         * ================================================= */

        if (
            $image === ''
            &&
            $formImagePath !== ''
        ) {

            if (
                !str_starts_with(
                    $formImagePath,
                    '/'
                )
            ) {

                throw new RuntimeException(
                    '기존 이미지 경로는 / 로 시작해야 합니다.'
                );
            }


            $image =
                $formImagePath;
        }


        /* =================================================
         * 이미지가 아예 없는 경우
         * ================================================= */

        if ($image === '') {

            $image =
                $isGame
                    ? '/assets/images/games/default.webp'
                    : '/assets/images/apps/default.webp';
        }


        /* =================================================
         * GAME
         * ================================================= */

        if ($isGame) {


            if ($formBadge === '') {

                $formBadge =
                    '웹게임';
            }


            $item = [

                /*
                 * /games/... 폴더명
                 */

                'id' =>
                    $id,


                'title' =>
                    $formTitle,


                'description' =>
                    $formDescription,


                'image' =>
                    $image,


                /*
                 * 웹게임 / 보드게임 등
                 */

                'badge' =>
                    $formBadge,


                /*
                 * 개발중 / 테스트중 / 운영중 ...
                 */

                'status' =>
                    $formStatus,


                /*
                 * 웹 게임 실행 주소
                 */

                'url' =>
                    $formUrl,


                /*
                 * Android 버전 Google Play 주소
                 *
                 * 없으면 ''
                 *
                 * games/index.php에서는
                 * 값이 있을 때만 Google Play 버튼 표시
                 */

                'play_url' =>
                    $formPlayUrl,


                /*
                 * 기존 데이터 호환
                 */

                'action' =>
                    '게임 시작',


                /*
                 * 게임 실행 주소가
                 * 외부 사이트인지 판별
                 */

                'external' =>
                    preg_match(
                        '#^https?://#i',
                        $formUrl
                    ) === 1,


                'created_at' =>
                    date('c'),
            ];


        /* =================================================
         * APP
         * ================================================= */

        } else {


            $item = [

                /*
                 * Google Play 패키지 ID
                 */

                'id' =>
                    $id,


                'title' =>
                    $formTitle,


                'purpose' =>
                    $formPurpose,


                'description' =>
                    $formDescription,


                'image' =>
                    $image,


                /*
                 * 개발 상태
                 *
                 * 개발중
                 * 테스트중
                 * 운영중
                 * 준비중
                 * 완료
                 */

                'status' =>
                    $formStatus,


                /*
                 * 출시 여부는 개발 상태와 별도
                 *
                 * 예:
                 *
                 * 개발중 + 출시
                 */

                'released' =>
                    $formReleased,


                /*
                 * Google Play URL
                 */

                'url' =>
                    $formUrl,


                /*
                 * 기존 데이터 호환
                 *
                 * 실제 apps 페이지에서는
                 * Google Play 버튼으로 출력
                 */

                'action' =>
                    '자세히 보기',


                'external' =>
                    true,


                'created_at' =>
                    date('c'),
            ];
        }


        /* =================================================
         * JSON 저장
         * ================================================= */

        catalog_add(
            $type,
            $item
        );


        /* =================================================
         * 완료 후 목록으로
         * ================================================= */

        header(
            'Location: '
            . (
                $isGame
                    ? '/games/#'
                    : '/apps/#'
            )
            . rawurlencode($id)
        );

        exit;


    } catch (Throwable $e) {

        $error =
            $e->getMessage();
    }
}


/* =========================================================
 * HEADER
 * ========================================================= */

require dirname(__DIR__)
    . '/includes/header.php';

?>


<style>

/* =========================================================
 * 등록 화면
 * ========================================================= */

.add-project-wrap {

    max-width: 820px;

    margin:
        0 auto;
}


.add-project-card {

    padding: 28px;

    background:
        #111827;

    border:
        1px solid
        rgba(255,255,255,.13);

    border-radius:
        18px;
}


/* =========================================================
 * LABEL
 * ========================================================= */

.add-project-form label {

    display: block;

    margin-bottom:
        8px;

    color:
        #fff;

    font-weight:
        700;
}


/* =========================================================
 * INPUT
 * ========================================================= */

.add-project-form input[type="text"],
.add-project-form textarea,
.add-project-form select {

    width:
        100%;

    box-sizing:
        border-box;

    padding:
        13px 14px;

    margin-bottom:
        20px;

    border:
        1px solid
        rgba(0,0,0,.22);

    background:
        #fff;

    color:
        #111;

    font:
        inherit;
}


.add-project-form textarea {

    min-height:
        150px;

    resize:
        vertical;

    line-height:
        1.6;
}


/* =========================================================
 * FILE
 * ========================================================= */

.add-project-form input[type="file"] {

    width:
        100%;

    margin:
        5px 0 10px;

    color:
        #ddd;
}


/* =========================================================
 * 설명
 * ========================================================= */

.form-help {

    margin:
        -4px 0 20px;

    color:
        rgba(255,255,255,.55);

    font-size:
        13px;

    line-height:
        1.65;
}


.form-help strong {

    color:
        #8fd8ff;
}


/* =========================================================
 * 앱 출시 체크
 * ========================================================= */

.release-check {

    display:
        flex !important;

    align-items:
        center;

    gap:
        11px;

    margin:
        0 0 24px;

    padding:
        14px 16px;

    border:
        1px solid
        rgba(34,197,94,.30);

    border-radius:
        10px;

    background:
        rgba(34,197,94,.07);

    cursor:
        pointer;
}


.release-check input {

    width:
        19px;

    height:
        19px;

    margin:
        0;
}


.release-check-text strong {

    display:
        block;

    color:
        #72e693;
}


.release-check-text span {

    display:
        block;

    margin-top:
        3px;

    color:
        rgba(255,255,255,.55);

    font-size:
        12px;
}


/* =========================================================
 * 오류
 * ========================================================= */

.form-error {

    margin-bottom:
        22px;

    padding:
        14px 16px;

    border:
        1px solid
        rgba(255,80,80,.35);

    border-radius:
        10px;

    background:
        rgba(180,30,30,.35);

    color:
        #fff;
}


/* =========================================================
 * 버튼
 * ========================================================= */

.form-buttons {

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        12px;

    margin-top:
        10px;
}


/* =========================================================
 * URL 영역 구분
 * ========================================================= */

.url-section {

    margin-top:
        4px;

    padding:
        18px;

    border:
        1px solid
        rgba(25,184,255,.14);

    border-radius:
        12px;

    background:
        rgba(25,184,255,.035);
}


.url-section input[type="text"]:last-of-type {

    margin-bottom:
        12px;
}


/* =========================================================
 * 모바일
 * ========================================================= */

@media (max-width: 700px) {

    .add-project-card {

        padding:
            20px;
    }

}

</style>


<!-- =======================================================
     HEADER
     ======================================================= -->

<section class="subhero">

<div class="container">


    <p class="section-kicker">

        ADMIN

    </p>


    <h1>

        <?= $isGame
            ? '게임 추가'
            : '앱 추가'
        ?>

    </h1>


    <p>

        STIMPACK GAME LAB에 새로운

        <?= $isGame
            ? '웹게임'
            : '앱'
        ?>

        을 등록합니다.

    </p>


</div>

</section>


<!-- =======================================================
     FORM
     ======================================================= -->

<section class="section">

<div class="container">

<div class="add-project-wrap">

<div class="add-project-card">


<?php if ($error !== ''): ?>


    <div class="form-error">

        <?= e($error) ?>

    </div>


<?php endif; ?>


<form
    method="post"
    enctype="multipart/form-data"
    class="add-project-form"
>


    <!-- 종류 -->

    <input
        type="hidden"
        name="type"
        value="<?= e($type) ?>"
    >


    <!-- CSRF -->

    <input
        type="hidden"
        name="csrf"
        value="<?= e(
            gamelab_csrf_token()
        ) ?>"
    >


    <!-- ===================================================
         제목
         =================================================== -->

    <label for="project-title">

        제목

    </label>


    <input
        id="project-title"
        type="text"
        name="title"
        value="<?= e($formTitle) ?>"
        required
    >


    <?php if (!$isGame): ?>

        <!-- ===============================================
             제작 의도
             =============================================== -->

        <label for="project-purpose">

            제작 의도

        </label>


        <textarea
            id="project-purpose"
            name="purpose"
            placeholder="이 앱을 만들게 된 계기와 목적을 입력하세요"
        ><?= e($formPurpose) ?></textarea>

    <?php endif; ?>


    <!-- ===================================================
         설명
         =================================================== -->

    <label for="project-description">

        설명

    </label>


    <textarea
        id="project-description"
        name="description"
        required
    ><?= e($formDescription) ?></textarea>


    <!-- ===================================================
         상태
         =================================================== -->

    <label for="project-status">

        상태

    </label>


    <select
        id="project-status"
        name="status"
    >


        <?php

        $statusOptions = [

            '개발중',
            '테스트중',
            '운영중',
            '준비중',
            '완료',

        ];


        foreach (
            $statusOptions
            as $statusOption
        ):

        ?>


            <option
                value="<?= e($statusOption) ?>"
                <?= $formStatus === $statusOption
                    ? 'selected'
                    : ''
                ?>
            >

                <?= e($statusOption) ?>

            </option>


        <?php endforeach; ?>


    </select>


    <!-- ===================================================
         APP 출시 여부
         =================================================== -->

    <?php if (!$isGame): ?>


        <label class="release-check">


            <input
                type="checkbox"
                name="released"
                value="1"
                <?= $formReleased
                    ? 'checked'
                    : ''
                ?>
            >


            <span class="release-check-text">


                <strong>

                    Google Play 출시 완료

                </strong>


                <span>

                    체크하면 개발 상태 뱃지는 유지하고
                    옆에 [출시] 뱃지가 추가됩니다.

                </span>


            </span>


        </label>


    <?php endif; ?>


    <!-- ===================================================
         GAME 종류 / 배지
         =================================================== -->

    <?php if ($isGame): ?>


        <label for="project-badge">

            종류 / 배지

        </label>


        <input
            id="project-badge"
            type="text"
            name="badge"
            value="<?= e($formBadge) ?>"
            placeholder="웹게임"
        >


    <?php endif; ?>


    <!-- ===================================================
         대표 이미지
         =================================================== -->

    <label>

        대표 이미지 업로드

    </label>


    <input
        type="file"
        name="image"
        accept="
            .jpg,
            .jpeg,
            .png,
            .webp,
            image/jpeg,
            image/png,
            image/webp
        "
    >


    <div class="form-help">

        JPG / PNG / WEBP 이미지를 업로드하면
        자동으로

        <strong>
            1280 × 720
        </strong>

        크기의 WebP 이미지로 변환하여 저장합니다.

    </div>


    <!-- ===================================================
         기존 이미지
         =================================================== -->

    <label for="image-path">

        또는 기존 이미지 경로

    </label>


    <input
        id="image-path"
        type="text"
        name="image_path"
        value="<?= e($formImagePath) ?>"
        placeholder="/assets/images/..."
    >


    <!-- ===================================================
         GAME URLs
         =================================================== -->

    <?php if ($isGame): ?>


        <div class="url-section">


            <label for="project-url">

                게임 실행 URL

            </label>


            <input
                id="project-url"
                type="text"
                name="url"
                value="<?= e($formUrl) ?>"
                placeholder="/games/crimescene/"
                required
            >


            <div class="form-help">

                예:

                <strong>
                    /games/crimescene/
                </strong>

                <br>

                `/games/` 다음 폴더명인
                <strong>crimescene</strong>이

                게임 ID와 대표 이미지 파일명이 됩니다.

            </div>


            <!-- ===========================================
                 게임 Google Play
                 =========================================== -->

            <label for="game-play-url">

                게임 Google Play URL

            </label>


            <input
                id="game-play-url"
                type="text"
                name="play_url"
                value="<?= e($formPlayUrl) ?>"
                placeholder="https://play.google.com/store/apps/details?id=..."
            >


            <div class="form-help">

                Android 앱 버전이 출시된 게임만
                Google Play 주소를 입력하세요.

                <br>

                주소가 비어 있으면 게임 카드에는
                Google Play 버튼이 나타나지 않습니다.

            </div>


        </div>


    <!-- ===================================================
         APP Google Play URL
         =================================================== -->

    <?php else: ?>


        <div class="url-section">


            <label for="project-url">

                앱 / Google Play URL

            </label>


            <input
                id="project-url"
                type="text"
                name="url"
                value="<?= e($formUrl) ?>"
                placeholder="https://play.google.com/store/apps/details?id=pe.kr.forum.medicalrecord"
                required
            >


            <div class="form-help">

                Google Play URL의

                <strong>
                    id=...
                </strong>

                값이 앱 ID가 됩니다.

                <br>

                예:

                <strong>
                    pe.kr.forum.medicalrecord
                </strong>

                <br>

                대표 이미지도 자동으로:

                <strong>
                    pe.kr.forum.medicalrecord.webp
                </strong>

                로 저장됩니다.

            </div>


        </div>


    <?php endif; ?>


    <!-- ===================================================
         저장
         =================================================== -->

    <div class="form-buttons">


        <button
            type="submit"
            class="button primary"
        >

            저장

        </button>


        <a
            class="button secondary"
            href="<?= $isGame
                ? '/games/'
                : '/apps/'
            ?>"
        >

            취소

        </a>


    </div>


</form>


</div>

</div>

</div>

</section>


<?php

require dirname(__DIR__)
    . '/includes/footer.php';

?>