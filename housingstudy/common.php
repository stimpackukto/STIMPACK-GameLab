<?php
declare(strict_types=1);

// Reuse existing same-origin GameLab Google OAuth session. Do not use WebView
// for Google login: Android opens this site using an external browser.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => true,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
require_once dirname(__DIR__) . '/includes/db.php';

const HS_UPLOAD_DIR = '/var/lib/gamelab/housingstudy_uploads';
const HS_MAX_FILE = 5 * 1024 * 1024;

function hs_db(): PDO { return gamelab_db(); }

function hs_h(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function hs_me(): ?array {
    $user = $_SESSION['google_user'] ?? null;
    if (!is_array($user) || empty($user['sub'])) return null;
    // The immutable Google subject, not email or profile name, identifies an author.
    $hash = hash('sha256', (string)$user['sub']);
    return ['hash' => $hash, 'name' => '학습자-' . strtoupper(substr($hash, 0, 6))];
}
function hs_admin(): bool { return !empty($_SESSION['gamelab_admin']) && hs_me() !== null; }
function hs_csrf(): string {
    if (empty($_SESSION['hs_csrf'])) $_SESSION['hs_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['hs_csrf'];
}
function hs_require_write(): array {
    $me = hs_me();
    if (!$me) hs_fail(403, '구글 로그인 후 작성할 수 있어요.');
    $csrf = (string)($_POST['csrf'] ?? '');
    if ($csrf === '' || !hash_equals(hs_csrf(), $csrf)) hs_fail(403, '보안 확인 실패. 페이지를 다시 열어주세요.');
    return $me;
}
function hs_fail(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}
function hs_redirect(string $uri): never {
    header('Location: ' . $uri, true, 303);
    exit;
}
function hs_limit(string $text, int $max): string {
    if (mb_strlen($text, 'UTF-8') > $max) hs_fail(400, '글자 수가 너무 많아요. 최대 ' . $max . '자');
    return $text;
}
function hs_clean_url(string $input): ?string {
    $input = trim($input);
    if ($input === '') return null;
    if (strlen($input) > 2000) hs_fail(400, '자료 링크가 너무 깁니다.');
    if (!filter_var($input, FILTER_VALIDATE_URL)) hs_fail(400, '올바른 자료 주소를 입력하세요.');
    $parts = parse_url($input);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
        hs_fail(400, '자료 링크는 https:// 주소만 사용할 수 있어요.');
    }
    return $input;
}
function hs_categories(): array {
    return ['질문·답변', '공부자료', '학습팁', '자유'];
}
function hs_upload(): ?array {
    if (!isset($_FILES['attachment']) || (int)$_FILES['attachment']['error'] === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES['attachment'];
    if ((int)$file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] < 1
        || (int)$file['size'] > HS_MAX_FILE || !is_uploaded_file((string)$file['tmp_name'])) {
        hs_fail(400, '첨부파일은 5MB 이하의 정상적인 PDF 또는 이미지여야 해요.');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allow = ['application/pdf' => '.pdf', 'image/jpeg' => '.jpg', 'image/png' => '.png'];
    if (!isset($allow[$mime])) hs_fail(400, 'PDF, JPG, PNG만 첨부할 수 있어요.');
    $name = trim((string)$file['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $expected = $allow[$mime] === '.jpg' ? ['jpg','jpeg'] : [ltrim($allow[$mime], '.')];
    if (!in_array($ext, $expected, true)) hs_fail(400, '파일 확장자와 내용이 일치하지 않아요.');
    if ($mime === 'application/pdf') {
        $fp = fopen((string)$file['tmp_name'], 'rb');
        $signature = $fp ? fread($fp, 5) : '';
        if ($fp) fclose($fp);
        if ($signature !== '%PDF-') hs_fail(400, '정상적인 PDF 파일이 아닙니다.');
    } else if (@getimagesize((string)$file['tmp_name']) === false) {
        hs_fail(400, '정상적인 이미지 파일이 아닙니다.');
    }
    if (!is_dir(HS_UPLOAD_DIR) || !is_writable(HS_UPLOAD_DIR)) {
        hs_fail(503, '자료 업로드 디렉터리 설정이 필요합니다.');
    }
    $token = bin2hex(random_bytes(16));
    $disk = HS_UPLOAD_DIR . '/' . $token . $allow[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $disk)) hs_fail(500, '파일 저장에 실패했습니다.');
    chmod($disk, 0640);
    // Never store a path or MIME supplied by the client.
    return ['token' => $token, 'name' => hs_limit(mb_substr(basename($name), 0, 180), 180),
        'mime' => $mime, 'disk' => $disk];
}
function hs_rate_limit(PDO $db, string $table, string $hash, int $seconds): void {
    if (!in_array($table, ['housingstudy_posts', 'housingstudy_comments'], true)) hs_fail(500, '잘못된 설정');
    $sql = "SELECT COUNT(*) FROM {$table} WHERE author_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$hash]);
    if ((int)$stmt->fetchColumn() > 0) hs_fail(429, '잠시 기다린 뒤 다시 작성해주세요.');
}
function hs_blocks(PDO $db, ?array $me): array {
    if (!$me) return [];
    $stmt = $db->prepare('SELECT blocked_hash FROM housingstudy_blocks WHERE blocker_hash=?');
    $stmt->execute([$me['hash']]);
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}
function hs_post(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM housingstudy_posts WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
