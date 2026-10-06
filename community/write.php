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
            ':content' => $formContent,
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
        $error = $e->getMessage();

    } catch (Throwable $e) {
        $error = '게시판 데이터베이스에 연결할 수 없습니다. DB 테이블을 확인해주세요.';
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

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
