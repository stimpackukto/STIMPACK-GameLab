<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) hs_fail(400, '첨부파일 번호가 올바르지 않습니다.');
try {
    $db = hs_db();
    $post = hs_post($db, $id);
    if (!$post || empty($post['attachment_token']) || empty($post['attachment_mime'])) {
        hs_fail(404, '첨부파일을 찾을 수 없습니다.');
    }
    $allow = ['application/pdf' => '.pdf', 'image/jpeg' => '.jpg', 'image/png' => '.png'];
    $mime = (string)$post['attachment_mime'];
    if (!isset($allow[$mime])) hs_fail(404, '지원하지 않는 파일입니다.');
    $token = (string)$post['attachment_token'];
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) hs_fail(404, '잘못된 첨부파일입니다.');
    $path = HS_UPLOAD_DIR . '/' . $token . $allow[$mime];
    if (!is_file($path)) hs_fail(404, '파일이 저장소에 없습니다.');
    $name = basename((string)($post['attachment_name'] ?: '자료' . $allow[$mime]));
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="study-attachment' . $allow[$mime] . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-store');
    header('Content-Security-Policy: sandbox');
    readfile($path);
} catch (PDOException $e) {
    error_log('housingstudy download: db error');
    hs_fail(503, '첨부파일을 일시적으로 열 수 없습니다.');
}
