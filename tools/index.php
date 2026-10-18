<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/data.php';
require_once dirname(__DIR__) . '/includes/admin.php';
$isGameLabAdmin = gamelab_is_admin();
$pageTitle = '도구 | STIMPACK GAME LAB';
require dirname(__DIR__) . '/includes/header.php';
?>
<style>
/* 앱 / 웹게임 전체보기와 동일한 가로형 프로젝트 카드 */
.tool-list .project-row {align-items:start}
.tool-list .project-row > img {aspect-ratio:16/9;height:auto;max-height:none}
.tool-status {display:flex;align-items:center;flex-wrap:wrap;gap:7px}
.tool-status .mini-status {display:inline-block;padding:5px 10px;border-radius:20px;background:#153c54;color:#90d9ff;font-size:12px;font-weight:700}
.tool-list .project-description {color:#c4d6e5;line-height:1.7;white-space:normal;overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-clamp:2;overflow-wrap:anywhere}
.tool-list .project-description.expanded {display:block;overflow:visible}
.tool-list .project-actions {display:flex;flex-wrap:wrap;gap:10px;margin-top:17px}
.tool-list .project-body {min-width:0}
@media(max-width:700px){
 .tool-list .project-row {grid-template-columns:1fr;gap:15px}
 .tool-list .project-row > img {width:100%;height:auto;aspect-ratio:16/9}
 .tool-list .project-row h2 {font-size:23px}
}
</style>
<section class="subhero">
 <div class="container">
  <p class="section-kicker">UTILITIES</p>
  <h1>STIMPACK GAME LAB 도구</h1>
  <p>직접 개발하고 테스트하는 브라우저 확장 기능과 유틸리티를 소개합니다.</p>
  <?php if ($isGameLabAdmin): ?>
   <div class="app-admin-tools">
    <span class="admin-login-badge">✓ 관리자 로그인</span>
    <a class="button primary" href="/tools/manage.php">＋ 도구 추가</a>
   </div>
  <?php endif; ?>
 </div>
</section>
<section class="section">
 <div class="container detail-stack tool-list">
 <?php if (empty($tools)): ?><p>등록된 도구가 없습니다.</p><?php endif; ?>
 <?php foreach ($tools as $tool): ?>
 <?php
 $toolId = (string)($tool['id'] ?? '');
 $toolTitle = (string)($tool['title'] ?? '');
 $toolImage = trim((string)($tool['image'] ?? ''));
 $toolImageSrc = $toolImage;
 if ($toolImage !== '' && str_starts_with($toolImage, '/')) {
   $imageFile = dirname(__DIR__) . $toolImage;
   if (is_file($imageFile)) $toolImageSrc .= '?v=' . filemtime($imageFile);
 }
 $toolUrl = trim((string)($tool['url'] ?? ''));
 $toolDescription = (string)($tool['description'] ?? '');
 // 일반 텍스트 필드를 Markdown으로 오인해 입력한 경우 기호가 노출되지 않도록 정리.
 $toolDescription = preg_replace('/\\*\\*(.*?)\\*\\*/s', '$1', $toolDescription) ?? $toolDescription;
 $toolDescription = preg_replace('/(?<!\\*)\\*([^*\\r\\n]+)\\*(?!\\*)/', '$1', $toolDescription) ?? $toolDescription;
 $detailId = 'tool-description-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $toolId);
 ?>
 <article id="<?= e($toolId) ?>" class="project-row">
  <?php if ($toolImageSrc !== ''): ?>
   <img src="<?= e($toolImageSrc) ?>" alt="<?= e($toolTitle) ?>" loading="lazy">
  <?php else: ?>
   <div style="aspect-ratio:16/9;background:#0e324c;border-radius:10px;display:grid;place-items:center;font-size:40px" aria-hidden="true">🛠</div>
  <?php endif; ?>
  <div class="project-body">
   <div class="tool-status">
    <span class="mini-status"><?= e((string)($tool['platform'] ?? 'UTILITY')) ?></span>
    <span class="mini-status"><?= e((string)($tool['status'] ?? '개발중')) ?></span>
   </div>
   <h2><?= e($toolTitle) ?></h2>
   <div class="project-description" id="<?= e($detailId) ?>"><?= nl2br(e($toolDescription)) ?></div>
   <div class="project-actions">
    <button type="button" class="button secondary tool-detail-toggle" data-target="<?= e($detailId) ?>" aria-expanded="false">자세히 보기</button>
    <?php if ($toolUrl !== ''): ?>
     <a class="button primary" href="<?= e($toolUrl) ?>" <?= preg_match('~^https?://~i', $toolUrl) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>도구 이용하기</a>
    <?php endif; ?>
    <?php if ($isGameLabAdmin): ?>
     <a class="button secondary" href="/tools/manage.php?id=<?= rawurlencode($toolId) ?>">✎ 수정</a>
    <?php endif; ?>
   </div>
  </div>
 </article>
 <?php endforeach; ?>
 </div>
</section>
<script>
document.querySelectorAll('.tool-detail-toggle').forEach(function(button) {
 button.addEventListener('click', function() {
  var detail = document.getElementById(button.dataset.target);
  if (!detail) return;
  var expanded = detail.classList.toggle('expanded');
  button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  button.textContent = expanded ? '접기' : '자세히 보기';
 });
});
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
