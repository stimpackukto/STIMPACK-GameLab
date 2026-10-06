<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/data.php';

$pageTitle = 'STIMPACK LAB 커뮤니티';

$categories = ['전체', '공지', '게임', '앱', '개발', '버그·제안', '자유'];
$selectedCategory = trim((string)($_GET['category'] ?? '전체'));

if (!in_array($selectedCategory, $categories, true)) {
    $selectedCategory = '전체';
}

$communityFeed = [
    [
        'category' => '공지',
        'title' => 'STIMPACK LAB 커뮤니티를 시작합니다',
        'meta' => '게임 · 앱 · 개발 · 테스트 이야기를 한곳에서 나눕니다.',
        'date' => '2026.10.06',
    ],
];

foreach ($devlogs as $log) {
    $tag = (string)($log['tag'] ?? '');
    $category = '개발';

    if ($tag === '앱') {
        $category = '앱';
    } elseif (in_array($tag, ['게임', '크라임씬', '웹게임'], true)) {
        $category = '게임';
    }

    $communityFeed[] = [
        'category' => $category,
        'title' => (string)($log['title'] ?? ''),
        'meta' => '개발 기록',
        'date' => (string)($log['date'] ?? ''),
    ];
}

foreach ($communityPosts as $post) {
    $sourceCategory = (string)($post['category'] ?? '개발');

    $category = match ($sourceCategory) {
        '웹게임' => '게임',
        'Android' => '앱',
        '서버' => '개발',
        default => in_array($sourceCategory, $categories, true) ? $sourceCategory : '개발',
    };

    $communityFeed[] = [
        'category' => $category,
        'title' => (string)($post['title'] ?? ''),
        'meta' => (string)($post['meta'] ?? ''),
        'date' => '',
    ];
}

$visiblePosts = array_values(array_filter(
    $communityFeed,
    static fn(array $post): bool =>
        $selectedCategory === '전체'
        || $post['category'] === $selectedCategory
));

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

            <span
                class="community-compose is-disabled"
                title="게시 기능 준비 중"
                aria-disabled="true"
            >
                새글 +
            </span>
        </div>

        <div class="community-board">
            <div class="community-board-head">
                <span>분류</span>
                <span>제목</span>
                <span>날짜</span>
            </div>

            <?php if ($visiblePosts): ?>
                <?php foreach ($visiblePosts as $post): ?>
                    <article class="community-row">
                        <div>
                            <span class="community-category"><?= e($post['category']) ?></span>
                        </div>

                        <div class="community-main">
                            <h2><?= e($post['title']) ?></h2>
                            <?php if ($post['meta'] !== ''): ?>
                                <p><?= e($post['meta']) ?></p>
                            <?php endif; ?>
                        </div>

                        <time><?= e($post['date'] !== '' ? $post['date'] : '준비 중') ?></time>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="community-empty">
                    아직 이 카테고리에 등록된 글이 없습니다.
                </div>
            <?php endif; ?>
        </div>

        <div class="community-footnote">
            현재는 STIMPACK GAME LAB의 기존 개발 기록과 토론 주제를 한곳에 모아 보여주고 있습니다.
            글쓰기와 댓글 기능은 커뮤니티 게시 시스템 연결 후 활성화됩니다.
        </div>

    </div>
</section>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
