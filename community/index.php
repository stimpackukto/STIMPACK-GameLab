<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$pageTitle = 'STIMPACK LAB 커뮤니티';

$categories = ['전체', '공지', '게임', '앱', '개발', '버그·제안', '자유'];
$selectedCategory = trim((string)($_GET['category'] ?? '전체'));

if (!in_array($selectedCategory, $categories, true)) {
    $selectedCategory = '전체';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$posts = [];
$total = 0;
$dbReady = true;
$dbMessage = '';

try {
    $pdo = gamelab_db();

    if ($selectedCategory === '전체') {
        $countStmt = $pdo->query(
            'SELECT COUNT(*) FROM gamelab_community_posts'
        );
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT
                id,
                category,
                title,
                user_name,
                is_admin,
                view_count,
                created_at
             FROM gamelab_community_posts
             ORDER BY
                CASE WHEN category = \'공지\' THEN 0 ELSE 1 END,
                id DESC
             LIMIT :limit OFFSET :offset'
        );
    } else {
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM gamelab_community_posts
             WHERE category = :category'
        );
        $countStmt->execute([
            ':category' => $selectedCategory,
        ]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT
                id,
                category,
                title,
                user_name,
                is_admin,
                view_count,
                created_at
             FROM gamelab_community_posts
             WHERE category = :category
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':category', $selectedCategory);
    }

    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $posts = $stmt->fetchAll();

} catch (Throwable $e) {
    $dbReady = false;
    $dbMessage = '커뮤니티 데이터베이스 준비가 필요합니다.';
}

$totalPages = max(1, (int)ceil($total / $perPage));

require dirname(__DIR__) . '/includes/header.php';
?>

<section class="subhero community-hero">
    <div class="container">
        <p class="section-kicker">COMMUNITY</p>
        <h1>STIMPACK LAB 커뮤니티</h1>
        <p>
            게임과 앱, 개발 과정과 테스트 이야기부터 자유로운 의견까지
            STIMPACK GAME LAB의 모든 이야기를 한곳에서 나누는 공간입니다.
        </p>
    </div>
</section>

<section class="section community-section">
    <div class="container">

        <div class="community-toolbar">
            <nav class="community-tabs" aria-label="커뮤니티 카테고리">
                <?php foreach ($categories as $category): ?>
                    <?php
                    $href = $category === '전체'
                        ? '/community/'
                        : '/community/?category=' . rawurlencode($category);
                    ?>
                    <a
                        class="community-tab <?= $selectedCategory === $category ? 'active' : '' ?>"
                        href="<?= e($href) ?>"
                    >
                        <?= e($category) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <a
                class="community-compose"
                href="/community/write.php"
            >
                새글 +
            </a>
        </div>

        <?php if (!$dbReady): ?>
            <div class="notice-box community-db-notice">
                <b>게시판 DB 설정이 필요합니다.</b>
                <p><?= e($dbMessage) ?></p>
            </div>
        <?php else: ?>

            <div class="community-board">
                <div class="community-board-head">
                    <span>분류</span>
                    <span>제목</span>
                    <span>작성자</span>
                    <span>조회</span>
                    <span>날짜</span>
                </div>

                <?php if ($posts): ?>
                    <?php foreach ($posts as $post): ?>
                        <article class="community-row <?= $post['category'] === '공지' ? 'is-notice' : '' ?>">
                            <div>
                                <span class="community-category">
                                    <?= e((string)$post['category']) ?>
                                </span>
                            </div>

                            <div class="community-main">
                                <h2>
                                    <a
                                        class="community-title-link"
                                        href="/community/view.php?id=<?= (int)$post['id'] ?>"
                                    >
                                        <?= e((string)$post['title']) ?>
                                    </a>
                                </h2>
                            </div>

                            <div class="community-author">
                                <?= !empty($post['is_admin'])
                                    ? '개발자'
                                    : e((string)$post['user_name'])
                                ?>
                            </div>

                            <div class="community-views">
                                <?= number_format((int)$post['view_count']) ?>
                            </div>

                            <time>
                                <?= e(date('Y.m.d', strtotime((string)$post['created_at']))) ?>
                            </time>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="community-empty">
                        아직 등록된 글이 없습니다. 첫 글을 작성해보세요.
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="community-pagination" aria-label="페이지 이동">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php
                        $params = [];
                        if ($selectedCategory !== '전체') {
                            $params['category'] = $selectedCategory;
                        }
                        $params['page'] = $i;
                        $pageUrl = '/community/?' . http_build_query($params);
                        ?>
                        <a
                            class="<?= $i === $page ? 'active' : '' ?>"
                            href="<?= e($pageUrl) ?>"
                        >
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</section>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
