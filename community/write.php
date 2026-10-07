<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/db.php';

$categories = ['게임', '앱', '개발', '버그·제안', '자유'];

$isAdmin = gamelab_is_admin();
if ($isAdmin) {
    array_unshift($categories, '공지');
}

$googleUser = $_SESSION['google_user'] ?? [];

$googleSub = trim((string)($googleUser['sub'] ?? ''));
$userEmail = strtolower(trim((string)(
    $googleUser['email']
    ?? $_SESSION['google_email']
    ?? ''
)));
$userName = trim((string)($googleUser['name'] ?? ''));

$isLoggedIn = $userEmail !== '';

if ($userName === '' && $userEmail !== '') {
    $parts = explode('@', $userEmail);
    $userName = (string)($parts[0] ?? '사용자');
}

$identitySub = $googleSub !== ''
    ? $googleSub
    : ($userEmail !== '' ? 'email:' . hash('sha256', $userEmail) : '');

$formCategory = trim((string)($_POST['category'] ?? '자유'));
$formTitle = trim((string)($_POST['title'] ?? ''));
$formContent = trim((string)($_POST['content'] ?? ''));
$error = '';
$uploadedDiskPaths = [];

function community_cleanup_uploaded_images(array $paths): void
{
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

function community_store_uploaded_images(array $files): array
{
    if (
        !isset($files['name'], $files['tmp_name'], $files['error'], $files['size'])
        || !is_array($files['name'])
    ) {
        return [[], []];
    }

    $indexes = [];
    foreach ($files['name'] as $index => $name) {
        $error = (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_NO_FILE && trim((string)$name) !== '') {
            $indexes[] = $index;
        }
    }

    if (count($indexes) > 5) {
        throw new RuntimeException('이미지는 한 글에 최대 5장까지 첨부할 수 있습니다.');
    }

    if ($indexes === []) {
        return [[], []];
    }

    $root = dirname(__DIR__);
    $relativeDir = '/uploads/community/' . date('Y/m');
    $diskDir = $root . $relativeDir;

    if (!is_dir($diskDir) && !mkdir($diskDir, 0755, true) && !is_dir($diskDir)) {
        throw new RuntimeException('이미지 저장 폴더를 만들 수 없습니다.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $publicPaths = [];
    $diskPaths = [];

    try {
        foreach ($indexes as $index) {
        $error = (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string)($files['tmp_name'][$index] ?? '');
        $size = (int)($files['size'][$index] ?? 0);

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('이미지 업로드 중 오류가 발생했습니다.');
        }

        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            throw new RuntimeException('이미지는 장당 8MB 이하만 업로드할 수 있습니다.');
        }

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('올바른 업로드 파일이 아닙니다.');
        }

        $imageInfo = @getimagesize($tmp);
        if ($imageInfo === false) {
            throw new RuntimeException('이미지 파일만 첨부할 수 있습니다.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);

        if (!isset($allowed[$mime])) {
            throw new RuntimeException('JPG, PNG, WEBP 이미지만 첨부할 수 있습니다.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $diskPath = $diskDir . '/' . $filename;
        $publicPath = $relativeDir . '/' . $filename;

        if (!move_uploaded_file($tmp, $diskPath)) {
            throw new RuntimeException('이미지를 서버에 저장하지 못했습니다.');
        }

        @chmod($diskPath, 0644);

            $diskPaths[] = $diskPath;
            $publicPaths[] = $publicPath;
        }
    } catch (Throwable $e) {
        community_cleanup_uploaded_images($diskPaths);
        throw $e;
    }

    return [$publicPaths, $diskPaths];
}

if (!in_array($formCategory, $categories, true)) {
    $formCategory = '자유';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$isLoggedIn || $identitySub === '') {
            throw new RuntimeException(
                'Google 로그인 후 글을 작성할 수 있습니다.'
            );
        }

        if (!gamelab_verify_csrf((string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException(
                '잘못된 요청입니다. 페이지를 새로고침한 뒤 다시 시도해주세요.'
            );
        }

        if (!in_array($formCategory, $categories, true)) {
            throw new RuntimeException('잘못된 카테고리입니다.');
        }

        if ($formCategory === '공지' && !$isAdmin) {
            throw new RuntimeException('공지 글은 관리자만 작성할 수 있습니다.');
        }

        if ($formTitle === '') {
            throw new RuntimeException('제목을 입력해주세요.');
        }

        if (mb_strlen($formTitle, 'UTF-8') > 200) {
            throw new RuntimeException('제목은 200자 이하로 작성해주세요.');
        }

        if ($formContent === '') {
            throw new RuntimeException('내용을 입력해주세요.');
        }

        if (mb_strlen($formContent, 'UTF-8') > 20000) {
            throw new RuntimeException('내용은 20,000자 이하로 작성해주세요.');
        }

        [$uploadedPublicPaths, $uploadedDiskPaths] = community_store_uploaded_images(
            $_FILES['images'] ?? []
        );

        $contentForDb = $formContent;
        if ($uploadedPublicPaths !== []) {
            $imageTokens = array_map(
                static fn(string $path): string => '[[community-image:' . $path . ']]',
                $uploadedPublicPaths
            );
            $contentForDb .= "\n\n" . implode("\n", $imageTokens);
        }

        $pdo = gamelab_db();

        $stmt = $pdo->prepare(
            'INSERT INTO gamelab_community_posts
            (
                category,
                title,
                content,
                google_sub,
                user_name,
                user_email,
                is_admin,
                view_count,
                created_at,
                updated_at
            )
            VALUES
            (
                :category,
                :title,
                :content,
                :google_sub,
                :user_name,
                :user_email,
                :is_admin,
                0,
                NOW(),
                NOW()
            )'
        );

        $stmt->execute([
            ':category' => $formCategory,
            ':title' => $formTitle,
            ':content' => $contentForDb,
            ':google_sub' => $identitySub,
            ':user_name' => $userName,
            ':user_email' => $userEmail,
            ':is_admin' => $isAdmin ? 1 : 0,
        ]);

        $postId = (int)$pdo->lastInsertId();

        header(
            'Location: /community/view.php?id=' . $postId
        );
        exit;

    } catch (RuntimeException $e) {
        community_cleanup_uploaded_images($uploadedDiskPaths);
        $error = $e->getMessage();

    } catch (Throwable $e) {
        community_cleanup_uploaded_images($uploadedDiskPaths);
        $error = '게시글을 저장하는 중 오류가 발생했습니다.';
    }
}

$pageTitle = '커뮤니티 새글 쓰기';

require dirname(__DIR__) . '/includes/header.php';
?>

<section class="subhero community-hero">
    <div class="container">
        <p class="section-kicker">COMMUNITY WRITE</p>
        <h1>새글 쓰기</h1>
        <p>STIMPACK LAB 커뮤니티에 새로운 글을 작성합니다.</p>
    </div>
</section>

<section class="section community-write-section">
    <div class="container">
        <div class="community-write-wrap">

            <?php if (!$isLoggedIn): ?>

                <div class="community-write-card community-login-card">
                    <h2>Google 로그인이 필요합니다.</h2>
                    <p>
                        커뮤니티 글 작성은 Google 로그인 사용자만 이용할 수 있습니다.
                    </p>
                    <a
                        class="button primary"
                        href="/auth/google_login.php"
                    >
                        Google 로그인
                    </a>
                    <a
                        class="button secondary"
                        href="/community/"
                    >
                        목록으로
                    </a>
                </div>

            <?php else: ?>

                <div class="community-write-card">

                    <?php if ($error !== ''): ?>
                        <div class="community-form-error">
                            <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <form
                        method="post"
                        enctype="multipart/form-data"
                        class="community-form"
                        autocomplete="off"
                    >
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= e(gamelab_csrf_token()) ?>"
                        >

                        <div class="community-writer-line">
                            <span>작성자</span>
                            <strong>
                                <?= $isAdmin ? '개발자' : e($userName) ?>
                            </strong>
                        </div>

                        <label for="community-category">
                            분류
                        </label>

                        <select
                            id="community-category"
                            name="category"
                            required
                        >
                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= e($category) ?>"
                                    <?= $formCategory === $category ? 'selected' : '' ?>
                                >
                                    <?= e($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="community-title">
                            제목
                        </label>

                        <input
                            id="community-title"
                            type="text"
                            name="title"
                            value="<?= e($formTitle) ?>"
                            maxlength="200"
                            required
                            placeholder="제목을 입력하세요"
                        >

                        <label for="community-content">
                            내용
                        </label>

                        <textarea
                            id="community-content"
                            name="content"
                            maxlength="20000"
                            required
                            placeholder="내용을 입력하세요"
                        ><?= e($formContent) ?></textarea>

                        <label for="community-images">
                            이미지 첨부
                        </label>

                        <div class="community-image-upload">
                            <input
                                id="community-images"
                                type="file"
                                name="images[]"
                                accept="image/jpeg,image/png,image/webp"
                                multiple
                            >
                            <p>
                                JPG · PNG · WEBP / 최대 5장 / 장당 8MB 이하
                            </p>
                            <div
                                id="community-image-preview"
                                class="community-image-preview"
                                aria-live="polite"
                            ></div>
                        </div>

                        <div class="community-form-buttons">
                            <button
                                type="submit"
                                class="button primary"
                            >
                                등록
                            </button>

                            <a
                                class="button secondary"
                                href="/community/"
                            >
                                취소
                            </a>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </div>
    </div>
</section>

<script>
(() => {
    const input = document.getElementById('community-images');
    const preview = document.getElementById('community-image-preview');

    if (!input || !preview) {
        return;
    }

    input.addEventListener('change', () => {
        preview.innerHTML = '';

        const files = Array.from(input.files || []);

        if (files.length > 5) {
            preview.textContent = '이미지는 최대 5장까지 선택할 수 있습니다.';
            return;
        }

        files.forEach((file) => {
            if (!file.type.startsWith('image/')) {
                return;
            }

            const item = document.createElement('div');
            item.className = 'community-image-preview-item';

            const image = document.createElement('img');
            image.alt = file.name;
            image.loading = 'lazy';

            const reader = new FileReader();
            reader.addEventListener('load', () => {
                image.src = String(reader.result || '');
            });
            reader.readAsDataURL(file);

            const name = document.createElement('span');
            name.textContent = file.name;

            item.appendChild(image);
            item.appendChild(name);
            preview.appendChild(item);
        });
    });
})();
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
