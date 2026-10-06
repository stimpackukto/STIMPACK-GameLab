<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/data.php';

$pageTitle = '개발자 토론';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="subhero">
    <div class="container">
        <p class="section-kicker">COMMUNITY</p>
        <h1>개발자 토론</h1>
        <p>STIMPACK GAME LAB은 프로젝트 소개와 개발 콘텐츠를 맡고, 실제 토론은 Reddit을 활용하는 방향입니다.</p>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="notice-box">
            <b>Reddit 커뮤니티 연결 예정</b>
            <p>서브레딧 주소가 확정되면 <code>includes/config.php</code>의 <code>REDDIT_URL</code>만 변경하면 됩니다.</p>
            <?php if (REDDIT_URL !== '#'): ?>
                <a class="button primary small" href="<?= e(REDDIT_URL) ?>" target="_blank" rel="noopener">Reddit에서 토론하기 →</a>
            <?php endif; ?>
        </div>
        <div class="text-list large">
            <?php foreach ($communityPosts as $post): ?>
                <article>
                    <span class="list-tag"><?= e($post['category']) ?></span>
                    <div>
                        <h2><?= e($post['title']) ?></h2>
                        <p class="muted"><?= e($post['meta']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
