<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/data.php';

$pageTitle = '개발일지';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="subhero">
    <div class="container">
        <p class="section-kicker">DEV LOG</p>
        <h1>개발일지</h1>
        <p>STIMPACK GAME LAB에서 만들고 고치고 테스트한 과정을 기록합니다.</p>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="text-list large">
            <?php foreach ($devlogs as $log): ?>
                <article>
                    <span class="list-tag"><?= e($log['tag']) ?></span>
                    <div>
                        <h2><?= e($log['title']) ?></h2>
                        <p class="muted">개발일지 본문은 이후 게시물 저장 구조를 결정한 뒤 연결합니다.</p>
                    </div>
                    <time><?= e($log['date']) ?></time>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
