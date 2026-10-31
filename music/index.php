<?php
declare(strict_types=1);

/* STIMPACK GAME LAB — original music sharing
 * Read access: public; uploads: signed-in Google users; removals: author or admin.
 * Only non-executable audio extensions are stored, using random file names.
 */
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/db.php';

$root = __DIR__;
$audioDir = $root . '/uploads';
$dataDir = $root . '/data';
$indexFile = $dataDir . '/tracks.php';
$commentsFile = $dataDir . '/comments.php'; // Legacy comments, read-only for migration
$prefix = "<?php http_response_code(404); exit; ?>\n";
$maxBytes = 10 * 1024 * 1024;
$email = gamelab_login_email();
$admin = gamelab_is_admin();
$messages = [];
$error = '';

function music_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function music_read(string $file, string $prefix): array {
    if (!is_file($file)) return [];
    $s = @file_get_contents($file);
    if (!is_string($s) || !str_starts_with($s, $prefix)) return [];
    $v = json_decode(substr($s, strlen($prefix)), true);
    return is_array($v) ? $v : [];
}
function music_save_cover(array $file, string $dir, string $id): string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('사진 업로드 오류: ' . $error);
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024 || !is_uploaded_file($tmp)) throw new RuntimeException('사진 원본은 5MB 이하로 선택해 주세요.');
    $meta = @getimagesize($tmp);
    if (!is_array($meta) || !in_array((int)$meta[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
        || $meta[0] < 1 || $meta[1] < 1 || $meta[0] > 4096 || $meta[1] > 4096) {
        throw new RuntimeException('JPG, PNG, WebP 사진(최대 4096px)만 지원합니다.');
    }
    if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) throw new RuntimeException('서버 PHP GD WebP 기능이 필요합니다.');
    $src = match ((int)$meta[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
    };
    if (!$src) throw new RuntimeException('사진을 읽을 수 없습니다.');
    $dst = imagecreatetruecolor(256, 256);
    if (!$dst) { imagedestroy($src); throw new RuntimeException('사진 변환에 실패했습니다.'); }
    // Keep square artwork without stretching; crop evenly around the center.
    $side = min((int)$meta[0], (int)$meta[1]);
    $left = intdiv((int)$meta[0] - $side, 2);
    $top = intdiv((int)$meta[1] - $side, 2);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    $ok = imagecopyresampled($dst, $src, 0, 0, $left, $top, 256, 256, $side, $side);
    imagedestroy($src);
    if (!$ok) { imagedestroy($dst); throw new RuntimeException('사진 크기 조정에 실패했습니다.'); }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        imagedestroy($dst);
        throw new RuntimeException('사진 저장 폴더에 접근할 수 없습니다.');
    }
    $filename = $id . '-cover-' . bin2hex(random_bytes(6)) . '.webp';
    $destination = $dir . '/' . $filename;
    $saved = @imagewebp($dst, $destination, 80);
    imagedestroy($dst);
    if (!$saved || !is_file($destination) || filesize($destination) === 0) {
        @unlink($destination);
        throw new RuntimeException('WebP 사진 저장에 실패했습니다.');
    }
    @chmod($destination, 0644);
    return $filename;
}
function music_mutate(string $dir, string $file, string $prefix, callable $fn): void {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('저장 디렉터리를 만들 수 없습니다.');
    $lock = @fopen($dir . '/.music.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('저장 잠금을 확보하지 못했습니다.');
    try {
        $tracks = music_read($file, $prefix);
        $fn($tracks);
        $tmp = tempnam($dir, '.tracks-');
        if ($tmp === false) throw new RuntimeException('저장 파일을 만들 수 없습니다.');
        $json = json_encode($tracks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $prefix . $json) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('목록 저장에 실패했습니다.');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($email === '') throw new RuntimeException('음악을 등록하려면 구글 로그인이 필요합니다.');
        if (!gamelab_verify_csrf((string)($_POST['csrf'] ?? ''))) throw new RuntimeException('요청이 만료되었습니다. 새로고침 후 다시 시도해 주세요.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'comment') {
            $trackId = (string)($_POST['track_id'] ?? '');
            $body = trim((string)($_POST['body'] ?? ''));
            if (!preg_match('/^[a-f0-9]{32}$/', $trackId)) throw new RuntimeException('잘못된 음악입니다.');
            if ($body === '' || mb_strlen($body, 'UTF-8') > 1000) throw new RuntimeException('댓글은 1~1000자로 입력해 주세요.');
            $exists = false;
            foreach (music_read($indexFile, $prefix) as $track) {
                if (($track['id'] ?? '') === $trackId) { $exists = true; break; }
            }
            if (!$exists) throw new RuntimeException('음악을 찾을 수 없습니다.');
            $google = is_array($_SESSION['google_user'] ?? null) ? $_SESSION['google_user'] : [];
            $writer = trim((string)($google['name'] ?? ''));
            if ($writer === '') $writer = explode('@', $email)[0];
            $db = gamelab_db();
            $stmt = $db->prepare('INSERT INTO gamelab_music_comments (id, track_id, body, name, owner, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([bin2hex(random_bytes(12)), $trackId, $body, $writer, $email]);
            header('Location: /music/#music-' . $trackId, true, 303);
            exit;
        } elseif ($action === 'edit') {
            $id = (string)($_POST['id'] ?? '');
            $title = trim((string)($_POST['title'] ?? ''));
            $artist = trim((string)($_POST['artist'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new RuntimeException('잘못된 음악입니다.');
            if ($title === '' || mb_strlen($title) > 100 || mb_strlen($artist) > 80 || mb_strlen($description) > 500) {
                throw new RuntimeException('제목·제작자·소개를 확인해 주세요.');
            }
            if ($artist === '') $artist = explode('@', $email)[0];
            // Check ownership before accepting the optional replacement image.
            $allowed = false;
            foreach (music_read($indexFile, $prefix) as $track) {
                if (($track['id'] ?? '') !== $id) continue;
                if (!$admin && strcasecmp((string)($track['owner'] ?? ''), $email) !== 0) throw new RuntimeException('본인 음악만 수정할 수 있습니다.');
                $allowed = true;
                break;
            }
            if (!$allowed) throw new RuntimeException('음악을 찾을 수 없습니다.');
            $cover = music_save_cover((array)($_FILES['cover'] ?? []), $audioDir, $id);
            $oldCover = '';
            try {
                music_mutate($dataDir, $indexFile, $prefix, function (&$tracks) use ($id, $email, $admin, $title, $artist, $description, $cover, &$oldCover): void {
                    foreach ($tracks as &$track) {
                        if (($track['id'] ?? '') !== $id) continue;
                        if (!$admin && strcasecmp((string)($track['owner'] ?? ''), $email) !== 0) throw new RuntimeException('본인 음악만 수정할 수 있습니다.');
                        $track['title'] = $title;
                        $track['artist'] = $artist;
                        $track['description'] = $description;
                        if ($cover !== '') {
                            $oldCover = (string)($track['cover'] ?? '');
                            $track['cover'] = $cover;
                        }
                        return;
                    }
                    throw new RuntimeException('음악을 찾을 수 없습니다.');
                });
            } catch (Throwable $e) {
                if ($cover !== '') @unlink($audioDir . '/' . $cover);
                throw $e;
            }
            if ($cover !== '' && preg_match('/^[a-f0-9]{32}-cover-[a-f0-9]{12}\\.(jpg|png|webp)$/', $oldCover)) @unlink($audioDir . '/' . $oldCover);
            header('Location: /music/#music-' . $id, true, 303);
            exit;
        } elseif ($action === 'cover') {
            $id = (string)($_POST['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new RuntimeException('잘못된 음악입니다.');
            $canEdit = false;
            foreach (music_read($indexFile, $prefix) as $track) {
                if (($track['id'] ?? '') !== $id) continue;
                if (!$admin && strcasecmp((string)($track['owner'] ?? ''), $email) !== 0) throw new RuntimeException('본인 음악만 수정할 수 있습니다.');
                $canEdit = true; break;
            }
            if (!$canEdit) throw new RuntimeException('음악을 찾을 수 없습니다.');
            $cover = music_save_cover((array)($_FILES['cover'] ?? []), $audioDir, $id);
            if ($cover === '') throw new RuntimeException('사진을 선택해 주세요.');
            $previous = '';
            try {
                music_mutate($dataDir, $indexFile, $prefix, function (&$tracks) use ($id, $email, $admin, $cover, &$previous): void {
                    foreach ($tracks as &$track) {
                        if (($track['id'] ?? '') !== $id) continue;
                        if (!$admin && strcasecmp((string)($track['owner'] ?? ''), $email) !== 0) throw new RuntimeException('수정 권한이 없습니다.');
                        $previous = (string)($track['cover'] ?? '');
                        $track['cover'] = $cover;
                        return;
                    }
                    throw new RuntimeException('음악을 찾을 수 없습니다.');
                });
            } catch (Throwable $e) {
                @unlink($audioDir . '/' . $cover);
                throw $e;
            }
            if (preg_match('/^[a-f0-9]{32}-cover-[a-f0-9]{12}\.(jpg|png|webp)$/', $previous)) @unlink($audioDir . '/' . $previous);
            header('Location: /music/#music-' . $id, true, 303);
            exit;
        } elseif ($action === 'upload') {
            $title = trim((string)($_POST['title'] ?? ''));
            $artist = trim((string)($_POST['artist'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($title === '' || mb_strlen($title) > 100 || mb_strlen($artist) > 80 || mb_strlen($description) > 500) throw new RuntimeException('제목·제작자·설명을 확인해 주세요.');
            if ($artist === '') $artist = explode('@', $email)[0];
            if (empty($_POST['rights'])) throw new RuntimeException('직접 제작했거나 공개 권한이 있는 음악만 등록할 수 있습니다.');
            $f = $_FILES['audio'] ?? null;
            if (!is_array($f)) throw new RuntimeException('음원 파일을 선택해 주세요.');
            $uploadError = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('PHP 업로드 허용 크기를 초과했습니다. music/.user.ini 설정을 확인해 주세요.');
            if ($uploadError !== UPLOAD_ERR_OK) throw new RuntimeException('음원 업로드에 실패했습니다. 오류 코드: ' . $uploadError);
            $size = (int)($f['size'] ?? 0);
            if ($size < 1 || $size > $maxBytes || !is_uploaded_file((string)$f['tmp_name'])) throw new RuntimeException('음원 크기는 10MB 이하여야 합니다.');
            // Fileinfo may label valid OGG/Opus as application/octet-stream.
            // Allow only supported file extensions; FFmpeg must successfully decode audio.
            $originalExt = strtolower((string)pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($originalExt, ['mp3', 'ogg', 'wav', 'm4a', 'webm'], true)) {
                throw new RuntimeException('MP3, OGG, WAV, M4A, WebM 음원만 등록할 수 있습니다.');
            }
            if (!is_dir($audioDir) && !@mkdir($audioDir, 0755, true) && !is_dir($audioDir)) throw new RuntimeException('음원 저장 디렉터리를 만들 수 없습니다.');
            $id = bin2hex(random_bytes(16));
            $cover = music_save_cover((array)($_FILES['cover'] ?? []), $audioDir, $id);
            // All uploads are transcoded to efficient OGG/Opus; source is not stored.
            // Use a bounded process and never build an unescaped shell command from user input.
            $ffmpeg = '/usr/bin/ffmpeg';
            if (!is_executable($ffmpeg) || !function_exists('proc_open')) {
                if ($cover !== '') @unlink($audioDir . '/' . $cover);
                throw new RuntimeException('서버에 FFmpeg 변환 기능이 없습니다. 관리자에게 문의해 주세요.');
            }
            $filename = $id . '.ogg';
            $dest = $audioDir . '/' . $filename;
            $command = [
                $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-i', (string)$f['tmp_name'], '-map', '0:a:0', '-vn',
                '-c:a', 'libopus', '-b:a', '96k', '-vbr', 'on',
                '-f', 'ogg', $dest
            ];
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('음원 압축을 시작할 수 없습니다.');
            fclose($pipes[0]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2], 2048);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0 || !is_file($dest) || filesize($dest) === 0) {
                @unlink($dest);
                if ($cover !== '') @unlink($audioDir . '/' . $cover);
                error_log('[music convert] ' . (string)$stderr);
                throw new RuntimeException('음원 압축에 실패했습니다. 파일 형식 또는 FFmpeg 설정을 확인해 주세요.');
            }
            @chmod($dest, 0644);
            try {
                music_mutate($dataDir, $indexFile, $prefix, function (&$tracks) use ($id, $filename, $title, $artist, $description, $email, $cover): void {
                    array_unshift($tracks, [
                        'id' => $id, 'file' => $filename, 'title' => $title, 'artist' => $artist,
                        'description' => $description, 'owner' => $email, 'created' => gmdate('c'), 'cover' => $cover
                    ]);
                });
            } catch (Throwable $e) {
                @unlink($audioDir . '/' . $filename);
                if ($cover !== '') @unlink($audioDir . '/' . $cover);
                throw $e;
            }
            $messages[] = '음악이 등록되었습니다.';
        } elseif ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $removed = null;
            $removedCover = '';
            music_mutate($dataDir, $indexFile, $prefix, function (&$tracks) use ($id, $email, $admin, &$removed, &$removedCover): void {
                foreach ($tracks as $i => $track) {
                    if (($track['id'] ?? '') !== $id) continue;
                    if (!$admin && strcasecmp((string)($track['owner'] ?? ''), $email) !== 0) throw new RuntimeException('본인 음악만 삭제할 수 있습니다.');
                    $removed = (string)$track['file'];
                    $removedCover = (string)($track['cover'] ?? '');
                    array_splice($tracks, $i, 1);
                    return;
                }
                throw new RuntimeException('음악을 찾을 수 없습니다.');
            });
            if ($removed && preg_match('/^[a-f0-9]{32}\.(mp3|ogg|wav|m4a|webm)$/', $removed)) @unlink($audioDir . '/' . $removed);
            if (preg_match('/^[a-f0-9]{32}-cover-[a-f0-9]{12}\\.(jpg|png|webp)$/', $removedCover)) @unlink($audioDir . '/' . $removedCover);
            $messages[] = '음악을 삭제했습니다.';
        } else throw new RuntimeException('잘못된 요청입니다.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$tracks = music_read($indexFile, $prefix);
$commentsByTrack = [];
$commentsError = '';
try {
    $db = gamelab_db();
    $stmt = $db->query('SELECT id, track_id, body, name, owner, DATE_FORMAT(created_at, \'%Y-%m-%d %H:%i\') AS created FROM gamelab_music_comments ORDER BY created_at ASC, id ASC');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $comment) {
        $trackId = (string)($comment['track_id'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $trackId)) $commentsByTrack[$trackId][] = $comment;
    }
} catch (Throwable $e) {
    error_log('[music comments] ' . $e->getMessage());
    $commentsError = '댓글 DB 테이블을 확인해 주세요.';
}
$pageTitle = '음악';
require dirname(__DIR__) . '/includes/header.php';
?>
<style>
.music-header-actions{margin-top:22px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
#music-upload-panel[hidden]{display:none}
.music-list-heading{margin:36px 0 18px}
.music-list-heading small{font-size:15px;color:#94a9ba}
.music-list{display:grid;gap:18px}
.music-card{display:grid;grid-template-columns:120px minmax(0,1fr);gap:20px;padding:22px;border:1px solid rgba(86,122,148,.55);border-radius:18px;background:linear-gradient(160deg,#102a40,#071a2a);box-shadow:0 10px 24px rgba(0,0,0,.16)}
.music-thumb{width:120px;height:120px;display:flex;align-items:center;justify-content:center;border-radius:15px;background:radial-gradient(circle at 30% 30%,rgba(77,187,255,.33),transparent 50%),linear-gradient(135deg,#144967,#0a2135);border:1px solid rgba(98,189,248,.25);color:#b9e8ff;font-size:52px}
.music-thumb img{width:100%;height:100%;object-fit:cover;border-radius:14px}
.music-body{min-width:0}
.music-title{font-size:25px;margin:2px 0 8px;color:#fff;overflow-wrap:anywhere}
.music-meta{display:flex;flex-wrap:wrap;gap:10px;color:#b1c5d6;font-size:14px}
.music-badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.music-badge{padding:5px 11px;border-radius:20px;background:rgba(41,93,129,.33);border:1px solid rgba(98,189,248,.23);font-size:12px;color:#9ddaff}
.music-description{margin:14px 0 0;color:#d7e4ee;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere}
.music-player-wrap{margin-top:18px;padding:12px;border:1px solid rgba(150,190,220,.17);background:rgba(255,255,255,.04);border-radius:14px}
.music-player{display:block;width:100%}
.music-comment-action-row{display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-top:10px}.music-comments .music-comment-form{display:block;width:100%;margin:0}.music-comments .music-comment-form textarea{width:100%;box-sizing:border-box}.music-comment-action-row .music-manage-actions{margin:0}.music-manage-actions{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-top:14px}.music-manage-actions .music-actions{margin-top:0}.music-edit-panel{margin-top:0}.music-edit-panel[open]{flex-basis:100%;order:3}.music-edit-panel summary{display:inline-flex;cursor:pointer;list-style:none}.music-edit-panel summary::-webkit-details-marker{display:none}.music-edit-form{margin-top:12px;display:grid;gap:12px;max-width:560px}.music-edit-form label{display:grid;gap:6px;color:#d2e1ec;font-size:14px}.music-edit-form input,.music-edit-form textarea{width:100%;box-sizing:border-box;background:#071c2e;border:1px solid #486b82;border-radius:8px;color:#fff;padding:10px;font:inherit}.music-edit-form button{justify-self:start}
.music-actions{margin-top:14px}
.music-cover-form{margin-top:16px;display:flex;gap:10px;align-items:end;flex-wrap:wrap;font-size:13px;color:#b1c5d6}.music-cover-form input{display:block;margin-top:6px;max-width:240px}
.music-empty{color:#a8bacb}
.music-comments{margin-top:22px;padding-top:16px;border-top:1px solid rgba(140,178,202,.22)}
.music-comments h4{margin:0 0 14px;font-size:16px;color:#d6e8f5}
.music-comment{padding:12px 0;border-top:1px solid rgba(140,178,202,.12)}
.music-comment:first-of-type{border-top:0}
.music-comment-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap;font-size:13px}
.music-comment-head time{color:#93abc0}
.music-comment p{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.65;margin:8px 0}
.music-comment-form textarea{width:100%;box-sizing:border-box;border:1px solid #4b6c84;border-radius:9px;background:#061b2c;color:#f1f7ff;padding:12px;min-height:72px;resize:vertical;display:block;margin-bottom:10px}
.music-comment-form button{cursor:pointer}
.music-comment-login{font-size:13px;color:#adc4d8}
.music-comments{margin-top:22px;padding:18px;border:1px solid #123b57;border-radius:13px;background:#071b2a}
.music-toolbar{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-top:18px}.music-toolbar .music-manage-actions{margin:0}.music-comments[hidden]{display:none}
.music-review-heading{font-size:17px;font-weight:700;color:#f4f8fc;margin-bottom:22px}
.music-review-heading small{font-size:13px;color:#9caebf}
.music-review-empty{color:#aebdcb;line-height:1.7;padding:6px 0 22px}
.music-review-item{padding:14px 0;border-top:1px solid rgba(140,178,202,.16)}
.music-review-item:first-of-type{border-top:0}
.music-review-item p{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.65;margin:8px 0}
.music-review-write{border-top:1px solid #234057;margin-top:12px;padding-top:16px}
.music-review-write a,.music-review-compose summary{color:#2eaaff;font-weight:700;text-decoration:none;cursor:pointer}
.music-review-compose summary{list-style:none;display:inline-block}
.music-review-compose summary::-webkit-details-marker{display:none}
.music-review-compose .music-comment-form{margin-top:16px}
.music-review-compose .music-comment-form button{margin-top:10px}
@media(max-width:700px){.music-card{grid-template-columns:1fr;padding:16px}.music-thumb{width:100px;height:100px}.music-title{font-size:22px}}
</style>
<section class="subhero">
  <div class="container">
    <p class="section-kicker">MUSIC</p>
    <h1>STIMPACK GAME LAB 음악</h1>
    <p>직접 만든 음악을 공유하고 자유롭게 감상하는 공간입니다. 듣기는 로그인 없이 가능합니다.</p>
    <?php if ($email === ''): ?>
      <a class="button secondary" href="/auth/google_login.php" style="margin-top:18px">Google 로그인</a>
    <?php else: ?>
      <div class="music-header-actions">
        <button type="button" id="music-add-toggle" class="button primary" aria-controls="music-upload-panel" aria-expanded="false">＋ 음악 추가</button>
      </div>
    <?php endif; ?>
  </div>
</section>
<main class="section">
<div class="container" style="max-width:1000px;min-height:50vh">
  <?php foreach ($messages as $m): ?><p role="status" style="color:#9be3ae"><?= music_h($m) ?></p><?php endforeach; ?>
  <?php if ($error): ?><p role="alert" style="color:#ffaaa7"><?= music_h($error) ?></p><?php endif; ?>
  <?php if ($commentsError): ?><p role="alert" style="color:#ffaaa7"><?= music_h($commentsError) ?></p><?php endif; ?>
  <?php if ($email !== ''): ?>
    <section id="music-upload-panel" hidden style="border:1px solid #54657a;border-radius:12px;padding:20px;margin:26px 0">
      <h2 style="margin-top:0;font-size:20px">내 음악 등록</h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= music_h(gamelab_csrf_token()) ?>">
        <input type="hidden" name="action" value="upload">
        <label>제목 <input name="title" maxlength="100" required style="display:block;width:100%;padding:10px;margin:7px 0 14px"></label>
        <label>제작자 <input name="artist" maxlength="80" placeholder="비우면 로그인 계정 이름 사용" style="display:block;width:100%;padding:10px;margin:7px 0 14px"></label>
        <label>소개 <textarea name="description" maxlength="500" rows="3" style="display:block;width:100%;padding:10px;margin:7px 0 14px"></textarea></label>
        <label>음악 파일 (MP3, OGG, WAV, M4A, WebM / 원본 10MB 이하 · OGG로 압축 저장)
          <input type="file" name="audio" accept=".mp3,.ogg,.wav,.m4a,.webm,audio/*" required style="display:block;margin:10px 0 16px">
        </label>
        <label>프로필 사진 (선택 / JPG, PNG, WebP 원본 5MB 이하 · 256×256 WebP 자동 압축)
          <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" style="display:block;margin:10px 0 16px">
        </label>
        <label style="display:block;margin-bottom:16px"><input type="checkbox" name="rights" value="1" required> 직접 제작했거나 공개·공유 권한이 있는 음원임을 확인합니다.</label>
        <button type="submit" style="padding:10px 22px;cursor:pointer">음악 등록</button>
      </form>
    </section>
  <?php endif; ?>
  <h2 class="music-list-heading">공개 음악 <small>(<?= count($tracks) ?>곡)</small></h2>
  <?php if (!$tracks): ?><p class="music-empty">아직 등록된 음악이 없습니다.</p><?php endif; ?>
  <div class="music-list">
  <?php foreach ($tracks as $track):
    $file = (string)($track['file'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}\.(mp3|ogg|wav|m4a|webm)$/', $file)) continue;
    $title = (string)($track['title'] ?? '제목 없음');
    $artist = (string)($track['artist'] ?? '알 수 없음');
    $created = substr((string)($track['created'] ?? ''), 0, 10);
    $description = trim((string)($track['description'] ?? ''));
    $ext = strtoupper((string)pathinfo($file, PATHINFO_EXTENSION));
  ?>
    <article class="music-card" id="music-<?= music_h((string)$track['id']) ?>">
      <div class="music-thumb">
        <?php $coverImage = (string)($track['cover'] ?? ''); ?>
        <?php if (preg_match('/^[a-f0-9]{32}-cover-[a-f0-9]{12}\\.(jpg|png|webp)$/', $coverImage)): ?>
          <img src="<?= music_h('/music/uploads/' . $coverImage) ?>" alt="<?= music_h($artist) ?> 프로필 사진" loading="lazy">
        <?php else: ?><span aria-hidden="true">♪</span><?php endif; ?>
      </div>
      <div class="music-body">
        <h3 class="music-title"><?= music_h($title) ?></h3>
        <div class="music-meta">
          <span>제작자 <?= music_h($artist) ?></span>
          <span aria-hidden="true">·</span>
          <span><?= music_h($created) ?></span>
        </div>
        <div class="music-badges">
          <span class="music-badge">공개 음악</span>
          <span class="music-badge"><?= music_h($ext) ?></span>
        </div>
        <?php if ($description !== ''): ?>
          <p class="music-description"><?= music_h($description) ?></p>
        <?php endif; ?>
        <div class="music-player-wrap">
          <audio class="music-player" controls controlsList="nodownload noplaybackrate" disablePictureInPicture oncontextmenu="return false" preload="metadata" src="<?= music_h('/music/uploads/' . $file) ?>"></audio>
        </div>
        <div class="music-toolbar">
          <button type="button" class="button secondary music-review-toggle" data-target="music-reviews-<?= music_h((string)$track['id']) ?>" aria-controls="music-reviews-<?= music_h((string)$track['id']) ?>" aria-expanded="false">리뷰 · 의견 <?= count($trackComments) ?></button>

        <?php if ($email !== '' && ($admin || strcasecmp($email, (string)($track['owner'] ?? '')) === 0)): ?>
          <div class="music-manage-actions">
            <form method="post" class="music-actions" onsubmit="return confirm('이 음악을 삭제할까요?')">
              <input type="hidden" name="csrf" value="<?= music_h(gamelab_csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= music_h((string)$track['id']) ?>">
              <button type="submit" class="button secondary">삭제</button>
            </form>
            <details class="music-edit-panel">
              <summary class="button secondary">수정</summary>
              <form method="post" enctype="multipart/form-data" class="music-edit-form">
                <input type="hidden" name="csrf" value="<?= music_h(gamelab_csrf_token()) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= music_h((string)$track['id']) ?>">
                <label>제목<input type="text" name="title" maxlength="100" required value="<?= music_h($title) ?>"></label>
                <label>제작자<input type="text" name="artist" maxlength="80" value="<?= music_h($artist) ?>"></label>
                <label>소개<textarea name="description" maxlength="500" rows="3"><?= music_h($description) ?></textarea></label>
                <label>프로필 사진 변경 (선택 · 원본 5MB 이하 · 자동 압축)<input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label>
                <button type="submit" class="button primary">수정 저장</button>
              </form>
            </details>
          </div>
        <?php endif; ?>
        </div>
        <section class="music-comments" id="music-reviews-<?= music_h((string)$track['id']) ?>" hidden>
          <?php $trackComments = $commentsByTrack[(string)$track['id']] ?? []; ?>
          <div class="music-review-heading">사용후기 <small>(<?= count($trackComments) ?>)</small></div>
          <?php if (!$trackComments): ?>
            <div class="music-review-empty">아직 등록된 의견이 없습니다.<br>첫 의견을 남겨보세요.</div>
          <?php else: ?>
            <?php foreach ($trackComments as $comment): ?>
              <div class="music-review-item">
                <div class="music-comment-head">
                  <strong><?= music_h((string)($comment['name'] ?? '방문자')) ?></strong>
                  <time><?= music_h((string)($comment['created'] ?? '')) ?></time>
                </div>
                <p><?= music_h((string)($comment['body'] ?? '')) ?></p>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          <div class="music-review-write">
            <?php if ($email !== ''): ?>
              <details class="music-review-compose">
                <summary>[의견 남기기]</summary>
                <form method="post" class="music-comment-form">
                  <input type="hidden" name="csrf" value="<?= music_h(gamelab_csrf_token()) ?>">
                  <input type="hidden" name="action" value="comment">
                  <input type="hidden" name="track_id" value="<?= music_h((string)$track['id']) ?>">
                  <textarea name="body" maxlength="1000" required placeholder="음악에 대한 감상을 남겨주세요"></textarea>
                  <button type="submit" class="button secondary">댓글 등록</button>
                </form>
              </details>
            <?php else: ?>
              <a href="/auth/google_login.php">[의견 남기기]</a>
            <?php endif; ?>
          </div>
        </section>      </div>
    </article>
  <?php endforeach; ?>
  </div>
</div>
</main>
<script>
(function () {
  document.querySelectorAll('.music-review-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
      const panel = document.getElementById(button.dataset.target);
      if (!panel) return;
      panel.hidden = !panel.hidden;
      button.setAttribute('aria-expanded', String(!panel.hidden));
    });
  });
  if (location.hash.startsWith('#music-')) {
    const trackId = location.hash.slice(7);
    const panel = document.getElementById('music-reviews-' + trackId);
    const button = document.querySelector('.music-review-toggle[data-target="music-reviews-' + trackId + '"]');
    if (panel && button) {
      panel.hidden = false;
      button.setAttribute('aria-expanded', 'true');
    }
  }
  const button = document.getElementById('music-add-toggle');
  const panel = document.getElementById('music-upload-panel');
  if (!button || !panel) return;
  button.addEventListener('click', function () {
    const opening = panel.hidden;
    panel.hidden = !opening;
    button.setAttribute('aria-expanded', opening ? 'true' : 'false');
    button.textContent = opening ? '－ 추가 닫기' : '＋ 음악 추가';
    if (opening) panel.scrollIntoView({behavior:'smooth', block:'start'});
  });
  <?php if ($error !== ''): ?>
  panel.hidden = false;
  button.setAttribute('aria-expanded', 'true');
  button.textContent = '－ 추가 닫기';
  <?php endif; ?>
})();
</script>
<?php
$footer = dirname(__DIR__) . '/includes/footer.php';
if (is_file($footer)) require $footer;
