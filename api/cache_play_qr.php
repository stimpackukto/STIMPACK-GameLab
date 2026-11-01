<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/catalog.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function qr_reply(int $code, bool $ok, string $message): never {
    http_response_code($code);
    echo json_encode(['ok'=>$ok,'message'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') qr_reply(405,false,'POST만 지원합니다.');
if (!gamelab_is_admin()) qr_reply(403,false,'관리자만 QR 파일을 저장할 수 있습니다.');
if (!gamelab_verify_csrf((string)($_POST['csrf'] ?? ''))) qr_reply(403,false,'인증 실패');
$id = trim((string)($_POST['id'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9._-]{1,160}$/', $id)) qr_reply(400,false,'잘못된 ID');
$url = null;
foreach (catalog_load('app') as $app) {
    if ((string)($app['id'] ?? '') === $id) { $url = (string)($app['url'] ?? ''); break; }
}
if (!$url || !filter_var($url,FILTER_VALIDATE_URL) ||
    strtolower((string)parse_url($url,PHP_URL_HOST)) !== 'play.google.com' ||
    (string)parse_url($url,PHP_URL_PATH) !== '/store/apps/details')
    qr_reply(400,false,'등록된 Google Play 주소가 없습니다.');
$fileName = hash('sha256', $id . "\n" . $url) . '.png';
$dir = dirname(__DIR__) . '/assets/images/qrcodes';
$path = $dir . '/' . $fileName;
if (is_file($path)) qr_reply(200,true,'이미 저장된 QR입니다.');
$encoded = (string)($_POST['png'] ?? '');
if (strlen($encoded)>50000 || !str_starts_with($encoded,'data:image/png;base64,')) qr_reply(400,false,'이미지 형식 오류');
$binary=base64_decode(substr($encoded,22),true);
if ($binary===false || strlen($binary)>35000) qr_reply(400,false,'이미지 크기 오류');
$info=@getimagesizefromstring($binary);
if ($info===false || $info[2]!==IMAGETYPE_PNG || $info[0]!==114 || $info[1]!==114)
    qr_reply(400,false,'QR 크기 오류');
$image=@imagecreatefromstring($binary);
if ($image===false) qr_reply(400,false,'이미지 처리 오류');
if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) {
    imagedestroy($image);qr_reply(500,false,'저장 폴더 생성 실패');
}
$temp=tempnam($dir,'.qr-');
if ($temp===false) {imagedestroy($image);qr_reply(500,false,'임시 파일 실패');}
$ok=imagepng($image,$temp,6);
imagedestroy($image);
if (!$ok) { @unlink($temp);qr_reply(500,false,'PNG 저장 실패');}
@chmod($temp,0644);
if (!rename($temp,$path)) { @unlink($temp);qr_reply(500,false,'QR 저장 실패');}
qr_reply(200,true,'QR 저장 완료');
