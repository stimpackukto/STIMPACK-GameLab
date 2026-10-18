<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/catalog.php';
gamelab_require_admin();
$items = catalog_load('tool');
$id = trim((string)($_POST['id'] ?? $_GET['id'] ?? ''));
$current = null;
foreach ($items as $item) if ((string)($item['id'] ?? '') === $id) { $current = $item; break; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 try {
  if (!gamelab_verify_csrf((string)($_POST['csrf'] ?? ''))) throw new RuntimeException('잘못된 요청입니다.');
  $action = (string)($_POST['action'] ?? 'save');
  if ($action === 'delete') {
    if (!$current) throw new RuntimeException('삭제할 항목이 없습니다.');
    $items = array_values(array_filter($items, static fn($t) => (string)($t['id'] ?? '') !== $id));
  } else {
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $image = (string)($current['image'] ?? '');
    $platform = trim((string)($_POST['platform'] ?? 'UTILITY'));
    $status = trim((string)($_POST['status'] ?? '개발중'));
    if ($title === '' || $description === '') throw new RuntimeException('이름과 설명을 입력해주세요.');
    if ($url !== '' && !catalog_valid_url($url)) throw new RuntimeException('배포 URL이 올바르지 않습니다.');
    if (!$current) $id = bin2hex(random_bytes(8));
    $uploadedImage = catalog_upload_image_by_id('image', 'tool', $id);
    if ($uploadedImage !== '') $image = $uploadedImage;
    $updated = ['id'=>$id,'title'=>$title,'description'=>$description,'url'=>$url,'image'=>$image,'platform'=>$platform,'status'=>$status,'created_at'=>$current['created_at'] ?? date('c'),'updated_at'=>date('c')];
    $found = false;
    foreach ($items as $k=>$item) if ((string)($item['id'] ?? '') === $id) { $items[$k]=$updated; $found=true; break; }
    if (!$found) $items[]=$updated;
  }
  $file = catalog_file('tool');
  $dir = dirname($file);
  if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('저장 폴더 생성 실패');
  $fp = fopen($file,'c+');
  if (!$fp) throw new RuntimeException('저장 파일 열기 실패');
  try {
    if (!flock($fp,LOCK_EX)) throw new RuntimeException('잠금 실패');
    $old = stream_get_contents($fp);
    $latest = json_decode((string)$old,true);
    if (!is_array($latest)) $latest=[];
    if ($action==='delete') $latest=array_values(array_filter($latest, static fn($t)=>(string)($t['id']??'')!==$id));
    else {
      $found=false;
      foreach ($latest as $k=>$item) if ((string)($item['id']??'')===$id) { $latest[$k]=$updated; $found=true; break; }
      if (!$found) $latest[]=$updated;
    }
    $json=json_encode($latest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    rewind($fp); ftruncate($fp,0); if (fwrite($fp,$json) !== strlen($json)) throw new RuntimeException('저장 실패'); fflush($fp);
    flock($fp,LOCK_UN);
  } finally { fclose($fp); }
  header('Location: /tools/');exit;
 } catch(Throwable $e) { $error=$e->getMessage(); }
}
$pageTitle = '도구 관리';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="subhero"><div class="container"><h1><?= $current ? '도구 수정' : '도구 등록' ?></h1><a class="button secondary" href="/tools/">도구 목록</a></div></section>
<section class="section"><div class="container" style="max-width:740px">
<?php if($error !== ''): ?><p style="color:#ff8787"><?= e($error) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data" style="display:grid;gap:15px">
<input type="hidden" name="csrf" value="<?= e(gamelab_csrf_token()) ?>">
<input type="hidden" name="id" value="<?= e($id) ?>">
<label>도구 이름<input required name="title" value="<?= e((string)($_POST['title'] ?? $current['title'] ?? '')) ?>" style="display:block;width:100%;padding:12px"></label>
<label>설명<textarea required name="description" style="display:block;width:100%;padding:12px;min-height:110px"><?= e((string)($_POST['description'] ?? $current['description'] ?? '')) ?></textarea></label>
<label>플랫폼<select name="platform" style="display:block;width:100%;padding:12px"><?php foreach(['CHROME','WINDOWS','WEB','UTILITY','ANDROID','MAC'] as $p): ?><option value="<?= e($p) ?>" <?= (($_POST['platform'] ?? $current['platform'] ?? 'CHROME')===$p)?'selected':'' ?>><?= e($p) ?></option><?php endforeach; ?></select></label>
<label>상태<select name="status" style="display:block;width:100%;padding:12px"><?php foreach(['개발중','테스트중','배포중','운영중'] as $s): ?><option <?= (($_POST['status'] ?? $current['status'] ?? '개발중')===$s)?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
<label>배포·설치 URL (선택)<input name="url" value="<?= e((string)($_POST['url'] ?? $current['url'] ?? '')) ?>" placeholder="https://..." style="display:block;width:100%;padding:12px"></label>
<?php if (!empty($current['image'])): ?><div><strong>현재 대표 이미지</strong><img src="<?= e((string)$current['image']) ?>?v=<?= (int)time() ?>" alt="현재 대표 이미지" style="display:block;width:min(100%,460px);aspect-ratio:16/9;object-fit:cover;margin-top:10px;border-radius:12px"></div><?php endif; ?>
<label>대표 이미지 <?= $current ? '교체' : '등록' ?> (선택)<input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" style="display:block;width:100%;margin-top:10px"></label>
<p style="margin:0;color:#9cb1c5">JPG · PNG · WEBP, 최대 10MB · 자동 변환 1280 × 720 (16:9). 수정 시 파일을 선택하지 않으면 기존 이미지가 유지됩니다.</p>
<button class="button primary" name="action" value="save">저장하기</button>
<?php if($current): ?><button class="button secondary" name="action" value="delete" formnovalidate onclick="return confirm('이 도구를 삭제하시겠습니까?')">삭제하기</button><?php endif; ?>
</form>
<h2>등록된 도구</h2>
<?php foreach($items as $item): ?><p><a href="/tools/manage.php?id=<?= rawurlencode((string)$item['id']) ?>"><?= e((string)$item['title']) ?> · 수정</a></p><?php endforeach; ?>
</div></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
