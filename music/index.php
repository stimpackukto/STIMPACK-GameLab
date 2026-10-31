<?php
declare(strict_types=1);

/* STIMPACK GAME LAB — original music sharing
 * Read access: public; uploads: signed-in Google users; removals: author or admin.
 * Only non-executable audio extensions are stored, using random file names.
 */
require_once dirname(__DIR__) . '/includes/admin.php';

$root = __DIR__;
$audioDir = $root . '/uploads';
$dataDir = $root . '/data';
$indexFile = $dataDir . '/tracks.php';
$commentsFile = $dataDir . '/comments.php';
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
            music_mutate($dataDir, $commentsFile, $prefix, function (&$comments) use ($trackId, $body, $writer, $email): void {
                $comments[] = [
                    'id' => bin2hex(random_bytes(12)), 'track_id' => $trackId,
                    'body' => $body, 'name' => $writer, 'owner' => $email, 'created' => date('Y-m-d H:i')
                ];
            });
            header('Location: /music/#music-' . $trackId, true, 303);
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
            // All uploads are transcoded to efficient OGG/Opus; source is not stored.
            // Use a bounded process and never build an unescaped shell command from user input.
            $ffmpeg = '/usr/bin/ffmpeg';
            if (!is_executable($ffmpeg) || !function_exists('proc_open')) {
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
                error_log('[music convert] ' . (string)$stderr);
                throw new RuntimeException('음원 압축에 실패했습니다. 파일 형식 또는 FFmpeg 설정을 확인해 주세요.');
            }
            @chmod($dest, 0644);
            try {
                music_mutate($dataDir, $indexFile, $prefix, function (&$tracks) use ($id, $filename, $title, $artist, $description, $email): void {
                    array_unshift($tracks, [
                        'id' => $id, 'file' => $filename, 'title' => $title, 'artist' => $artist,
                        'description' => $description, 'owner' => $email, 'created' => gmdate('c')
                    ]);
                });
            } catch (Throwable $e) {
                @unlink($audioDir . '/' . $filename);
                throw $e;
            }
            $messages[] = '음악이 등록되었습니다.';
        } elseif ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $removed = null;
            music_mutate($dataDir, $indexFile, $prefix, function (&$tracks) use ($id, $email, $admin, &$removed): void {
                foreach ($tracks as $i => $track) {
                    if (($track['id'] ?? '') !== $id) continue;
                    if (!$admin && strcasecmp((string)($track['owner'] ?? ''), $email) !== 0) throw new RuntimeException('본인 음악만 삭제할 수 있습니다.');
                    $removed = (string)$track['file'];
                    array_splice($tracks, $i, 1);
                    return;
                }
                throw new RuntimeException('음악을 찾을 수 없습니다.');
            });
            if ($removed && preg_match('/^[a-f0-9]{32}\.(mp3|ogg|wav|m4a|webm)$/', $removed)) @unlink($audioDir . '/' . $removed);
            $messages[] = '음악을 삭제했습니다.';
        } else throw new RuntimeException('잘못된 요청입니다.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$tracks = music_read($indexFile, $prefix);
$allComments = music_read($commentsFile, $prefix);
$commentsByTrack = [];
foreach ($allComments as $comment) {
    $trackId = (string)($comment['track_id'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $trackId)) $commentsByTrack[$trackId][] = $comment;
}
$pageTitle = '음악';
require dirname(__DIR__) . '/includes/header.php';
?>
<style>
.music-list-heading{margin:36px 0 18px}
.music-list-heading small{font-size:15px;color:#94a9ba}
.music-list{display:grid;gap:18px}
.music-card{display:grid;grid-template-columns:120px minmax(0,1fr);gap:20px;padding:22px;border:1px solid rgba(86,122,148,.55);border-radius:18px;background:linear-gradient(160deg,#102a40,#071a2a);box-shadow:0 10px 24px rgba(0,0,0,.16)}
.music-thumb{width:120px;height:120px;display:flex;align-items:center;justify-content:center;border-radius:15px;background:radial-gradient(circle at 30% 30%,rgba(77,187,255,.33),transparent 50%),linear-gradient(135deg,#144967,#0a2135);border:1px solid rgba(98,189,248,.25);color:#b9e8ff;font-size:52px}
.music-body{min-width:0}
.music-title{font-size:25px;margin:2px 0 8px;color:#fff;overflow-wrap:anywhere}
.music-meta{display:flex;flex-wrap:wrap;gap:10px;color:#b1c5d6;font-size:14px}
.music-badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.music-badge{padding:5px 11px;border-radius:20px;background:rgba(41,93,129,.33);border:1px solid rgba(98,189,248,.23);font-size:12px;color:#9ddaff}
.music-description{margin:14px 0 0;color:#d7e4ee;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere}
.music-player-wrap{margin-top:18px;padding:12px;border:1px solid rgba(150,190,220,.17);background:rgba(255,255,255,.04);border-radius:14px}
.music-player{display:block;width:100%}
.music-actions{margin-top:14px}
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
@media(max-width:700px){.music-card{grid-template-columns:1fr;padding:16px}.music-thumb{width:100px;height:100px}.music-title{font-size:22px}}
</style>
<section class="subhero">
  <div class="container">
    <p class="section-kicker">MUSIC</p>
    <h1>STIMPACK GAME LAB 음악</h1>
    <p>직접 만든 음악을 공유하고 자유롭게 감상하는 공간입니다. 듣기는 로그인 없이 가능합니다.</p>
    <?php if ($email === ''): ?>
      <a class="button secondary" href="/auth/google_login.php" style="margin-top:18px">Google 로그인</a>
    <?php endif; ?>
  </div>
</section>
<main class="section">
<div class="container" style="max-width:1000px;min-height:50vh">
  <?php foreach ($messages as $m): ?><p role="status" style="color:#9be3ae"><?= music_h($m) ?></p><?php endforeach; ?>
  <?php if ($error): ?><p role="alert" style="color:#ffaaa7"><?= music_h($error) ?></p><?php endif; ?>
  <?php if ($email !== ''): ?>
    <section style="border:1px solid #54657a;border-radius:12px;padding:20px;margin:26px 0">
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
      <div class="music-thumb" aria-hidden="true">♪</div>
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
        <section class="music-comments">
          <?php $trackComments = $commentsByTrack[(string)$track['id']] ?? []; ?>
          <h4>댓글 <?= count($trackComments) ?></h4>
          <?php foreach ($trackComments as $comment): ?>
            <div class="music-comment">
              <div class="music-comment-head">
                <strong><?= music_h((string)($comment['name'] ?? '방문자')) ?></strong>
                <time><?= music_h((string)($comment['created'] ?? '')) ?></time>
              </div>
              <p><?= music_h((string)($comment['body'] ?? '')) ?></p>
            </div>
          <?php endforeach; ?>
          <?php if ($email !== ''): ?>
            <form method="post" class="music-comment-form">
              <input type="hidden" name="csrf" value="<?= music_h(gamelab_csrf_token()) ?>">
              <input type="hidden" name="action" value="comment">
              <input type="hidden" name="track_id" value="<?= music_h((string)$track['id']) ?>">
              <textarea name="body" maxlength="1000" required placeholder="음악에 대한 감상을 남겨주세요"></textarea>
              <button type="submit" class="button secondary">댓글 등록</button>
            </form>
          <?php else: ?>
            <p class="music-comment-login"><a href="/auth/google_login.php">구글 로그인</a> 후 댓글을 작성할 수 있습니다.</p>
          <?php endif; ?>
        </section>
        <?php if ($email !== '' && ($admin || strcasecmp($email, (string)($track['owner'] ?? '')) === 0)): ?>
          <form method="post" class="music-actions" onsubmit="return confirm('이 음악을 삭제할까요?')">
            <input type="hidden" name="csrf" value="<?= music_h(gamelab_csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= music_h((string)$track['id']) ?>">
            <button type="submit" class="button secondary">삭제</button>
          </form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</div>
</main>
<?php
$footer = dirname(__DIR__) . '/includes/footer.php';
if (is_file($footer)) require $footer;
