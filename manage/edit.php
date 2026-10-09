<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/catalog.php';


gamelab_require_admin();


$type = trim(
    (string)(
        $_POST['type']
        ?? $_GET['type']
        ?? ''
    )
);


$id = trim(
    (string)(
        $_POST['id']
        ?? $_GET['id']
        ?? ''
    )
);


if (
    !in_array(
        $type,
        ['app', 'game'],
        true
    )
) {

    http_response_code(400);

    exit('잘못된 종류입니다.');
}


if ($id === '') {

    http_response_code(400);

    exit('콘텐츠 ID가 없습니다.');
}


$isGame =
    $type === 'game';


/* =========================================================
 * 기존 데이터 찾기
 * ========================================================= */

$items =
    catalog_load($type);


$current = null;


foreach ($items as $item) {

    if (
        (string)(
            $item['id']
            ?? ''
        )
        === $id
    ) {

        $current = $item;

        break;
    }
}


if (!$current) {

    http_response_code(404);

    exit('등록된 항목을 찾을 수 없습니다.');
}


/* =========================================================
 * 폼 기본값
 * ========================================================= */

$formTitle = trim(
    (string)(
        $_POST['title']
        ?? $current['title']
        ?? ''
    )
);


$formPurpose = trim(
    (string)(
        $_POST['purpose']
        ?? $current['purpose']
        ?? ''
    )
);


$formDescription = trim(
    (string)(
        $_POST['description']
        ?? $current['description']
        ?? ''
    )
);


$formStatus = trim(
    (string)(
        $_POST['status']
        ?? $current['status']
        ?? '개발중'
    )
);


$formBadge = trim(
    (string)(
        $_POST['badge']
        ?? $current['badge']
        ?? '웹게임'
    )
);


$formUrl = trim(
    (string)(
        $_POST['url']
        ?? $current['url']
        ?? ''
    )
);


$formPlayUrl = trim(
    (string)(
        $_POST['play_url']
        ?? $current['play_url']
        ?? ''
    )
);


$formReleased =
    !$isGame
    &&
    (
        $_SERVER['REQUEST_METHOD'] === 'POST'
            ? !empty($_POST['released'])
            : !empty($current['released'])
    );


$currentReleasedAt = trim(
    (string)(
        $current['released_at']
        ?? ''
    )
);


$currentImage =
    (string)(
        $current['image']
        ?? ''
    );


$error = '';


/* =========================================================
 * 저장
 * ========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    try {


        if (
            !gamelab_verify_csrf(
                (string)(
                    $_POST['csrf']
                    ?? ''
                )
            )
        ) {

            throw new RuntimeException(
                '잘못된 요청입니다.'
            );
        }


        if ($formTitle === '') {

            throw new RuntimeException(
                '제목을 입력해주세요.'
            );
        }


        if ($formDescription === '') {

            throw new RuntimeException(
                '설명을 입력해주세요.'
            );
        }


        if (
            $formUrl !== ''
            &&
            !catalog_valid_url(
                $formUrl
            )
        ) {

            throw new RuntimeException(
                'URL 형식이 올바르지 않습니다.'
            );
        }


        if (
            $formPlayUrl !== ''
            &&
            !catalog_valid_url(
                $formPlayUrl
            )
        ) {

            throw new RuntimeException(
                'Google Play URL 형식이 올바르지 않습니다.'
            );
        }


        /* 대표 이미지 교체 */

        $newImage =
            catalog_upload_image_by_id(
                'image',
                $type,
                $id
            );


        $image =
            $newImage !== ''
                ? $newImage
                : $currentImage;


        /*
         * ID는 변경하지 않는다.
         *
         * 이유:
         * 리뷰 content_id 연결 유지
         */


        if ($isGame) {

            $updated = [

                'id' =>
                    $id,

                'title' =>
                    $formTitle,

                'description' =>
                    $formDescription,

                'image' =>
                    $image,

                'badge' =>
                    $formBadge ?: '웹게임',

                'status' =>
                    $formStatus,

                'url' =>
                    $formUrl,

                'play_url' =>
                    $formPlayUrl,

                'action' =>
                    '게임 시작',

                'external' =>
                    preg_match(
                        '#^https?://#i',
                        $formUrl
                    ) === 1,

                'created_at' =>
                    $current['created_at']
                    ?? date('c'),

                'updated_at' =>
                    date('c'),
            ];


        } else {


            $updated = [

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

                'status' =>
                    $formStatus,

                'released' =>
                    $formReleased,

                'released_at' =>
                    $formReleased
                        ? (
                            $currentReleasedAt !== ''
                                ? $currentReleasedAt
                                : date('c')
                        )
                        : '',

                'url' =>
                    $formUrl,

                'action' =>
                    '자세히 보기',

                'external' =>
                    preg_match(
                        '#^https?://#i',
                        $formUrl
                    ) === 1,

                'created_at' =>
                    $current['created_at']
                    ?? date('c'),

                'updated_at' =>
                    date('c'),
            ];
        }


        catalog_update(
            $type,
            $id,
            $updated
        );


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


$pageTitle =
    $isGame
        ? '게임 수정'
        : '앱 수정';


require dirname(__DIR__)
    . '/includes/header.php';
?>


<style>

.edit-wrap {
    max-width: 820px;
    margin: 0 auto;
}

.edit-card {
    padding: 28px;

    border:
        1px solid rgba(255,255,255,.13);

    border-radius: 18px;

    background: #111827;
}

.edit-card label {
    display: block;

    margin-bottom: 8px;

    font-weight: 700;
}

.edit-card input[type="text"],
.edit-card textarea,
.edit-card select {

    width: 100%;

    padding: 13px 14px;

    margin-bottom: 20px;

    background: #fff;

    color: #111;

    font: inherit;
}

.edit-card textarea {
    min-height: 150px;
    resize: vertical;
}

.edit-image {
    display: block;

    width: 320px;
    max-width: 100%;

    aspect-ratio: 16 / 9;

    object-fit: cover;

    margin-bottom: 14px;

    border-radius: 12px;
}

.edit-help {
    margin:
        -4px 0 20px;

    color:
        rgba(255,255,255,.55);

    font-size: 13px;
}

.edit-buttons {
    display: flex;
    gap: 10px;

    margin-top: 22px;
}

.edit-error {
    padding: 14px;

    margin-bottom: 20px;

    border-radius: 8px;

    background:
        rgba(170,30,30,.35);
}

.release-check {
    display: flex !important;

    align-items: center;

    gap: 10px;

    padding: 14px;

    margin-bottom: 20px;

    border:
        1px solid rgba(34,197,94,.3);

    border-radius: 10px;
}

.release-check input {
    width: 18px;
    height: 18px;
}

</style>


<section class="subhero">

<div class="container">

    <p class="section-kicker">
        ADMIN
    </p>

    <h1>

        <?= $isGame
            ? '게임 수정'
            : '앱 수정'
        ?>

    </h1>

    <p>
        ID:
        <?= e($id) ?>
    </p>

</div>

</section>


<section class="section">

<div class="container">

<div class="edit-wrap">

<div class="edit-card">


<?php if ($error !== ''): ?>

    <div class="edit-error">

        <?= e($error) ?>

    </div>

<?php endif; ?>


<form
    method="post"
    enctype="multipart/form-data"
>


<input
    type="hidden"
    name="type"
    value="<?= e($type) ?>"
>


<input
    type="hidden"
    name="id"
    value="<?= e($id) ?>"
>


<input
    type="hidden"
    name="csrf"
    value="<?= e(
        gamelab_csrf_token()
    ) ?>"
>


<label>
    제목
</label>

<input
    type="text"
    name="title"
    value="<?= e($formTitle) ?>"
    required
>


<?php if (!$isGame): ?>

    <label>
        제작 의도
    </label>

    <textarea
        name="purpose"
        placeholder="이 앱을 만들게 된 계기와 목적을 입력하세요"
    ><?= e($formPurpose) ?></textarea>

<?php endif; ?>


<label>
    설명
</label>

<textarea
    name="description"
    required
><?= e($formDescription) ?></textarea>


<label>
    상태
</label>

<select name="status">

<?php
foreach (
    [
        '개발중',
        '테스트중',
        '운영중',
        '준비중',
        '완료'
    ]
    as $status
):
?>

    <option
        value="<?= e($status) ?>"
        <?= $formStatus === $status
            ? 'selected'
            : ''
        ?>
    >

        <?= e($status) ?>

    </option>

<?php endforeach; ?>

</select>


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

        <span>
            Google Play 출시 완료
        </span>

    </label>

<?php endif; ?>


<?php if ($isGame): ?>

    <label>
        종류 / 배지
    </label>

    <input
        type="text"
        name="badge"
        value="<?= e($formBadge) ?>"
    >

<?php endif; ?>


<label>
    현재 대표 이미지
</label>


<?php if ($currentImage !== ''): ?>

    <img
        class="edit-image"
        src="<?= e(
            $currentImage
            . '?v='
            . time()
        ) ?>"
        alt=""
    >

<?php endif; ?>


<label>
    대표 이미지 교체
</label>

<input
    type="file"
    name="image"
    accept=".jpg,.jpeg,.png,.webp"
>


<div class="edit-help">

    새 이미지를 선택하지 않으면
    기존 이미지를 그대로 사용합니다.

</div>


<?php if ($isGame): ?>


    <label>
        게임 실행 URL
    </label>

    <input
        type="text"
        name="url"
        value="<?= e($formUrl) ?>"
        placeholder="/games/crimescene/"
    >


    <label>
        Google Play URL
    </label>

    <input
        type="text"
        name="play_url"
        value="<?= e($formPlayUrl) ?>"
        placeholder="https://play.google.com/store/apps/details?id=..."
    >


    <div class="edit-help">

        Google Play 주소를 입력하면
        게임 카드에 Google Play 버튼이 나타납니다.

    </div>


<?php else: ?>


    <label>
        Google Play URL
    </label>

    <input
        type="text"
        name="url"
        value="<?= e($formUrl) ?>"
    >


<?php endif; ?>


<div class="edit-buttons">


    <button
        type="submit"
        class="button primary"
    >

        수정 저장

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
