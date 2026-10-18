<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/data.php';
require_once dirname(__DIR__) . '/includes/admin.php';
$pageTitle = '도구 | STIMPACK GAME LAB';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="subhero"><div class="container"><p class="section-kicker">UTILITIES</p><h1>도구</h1><p>STIMPACK에서 개발한 브라우저 확장 기능과 유틸리티입니다.</p><a class="button secondary" href="/">홈으로</a><?php if (function_exists('gamelab_is_admin') && gamelab_is_admin()): ?><a class="button primary" href="/tools/manage.php">도구 관리</a><?php endif; ?></div></section>
<section class="section"><div class="container">
<?php if (empty($tools)): ?><p>등록된 도구가 없습니다.</p><?php endif; ?>
<div class="home-game-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px">
<?php foreach ($tools as $tool): ?>
<article id="<?= e((string)$tool['id']) ?>" style="background:#102638;border:1px solid #2c6382;border-radius:14px;overflow:hidden">
<?php if (!empty($tool['image'])): ?><img src="<?= e((string)$tool['image']) ?>" alt="" loading="lazy" style="width:100%;aspect-ratio:16/9;object-fit:cover"><?php endif; ?>
<div style="padding:20px"><p><?= e((string)($tool['platform'] ?? 'UTILITY')) ?> · <?= e((string)($tool['status'] ?? '개발중')) ?></p><h2><?= e((string)$tool['title']) ?></h2><p><?= e((string)$tool['description']) ?></p>
<?php if (!empty($tool['url'])): ?><a class="button primary" href="<?= e((string)$tool['url']) ?>" <?= preg_match('~^https?://~i',(string)$tool['url']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>도구 이용하기</a><?php endif; ?>
<?php if (gamelab_is_admin()): ?><a class="button secondary" href="/tools/manage.php?id=<?= rawurlencode((string)$tool['id']) ?>" style="margin-left:5px">관리자 수정</a><?php endif; ?>
</div></article>
<?php endforeach; ?>
</div></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
