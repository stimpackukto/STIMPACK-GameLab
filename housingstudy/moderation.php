<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
if (!hs_admin()) hs_fail(403, '관리자 전용입니다.');
try {
    $db = hs_db();
    $rows = $db->query('SELECT r.id, r.post_id, r.reason, r.created_at,
        p.title, p.deleted_at, p.author_name FROM housingstudy_reports r
        JOIN housingstudy_posts p ON p.id=r.post_id ORDER BY r.id DESC LIMIT 100')->fetchAll();
} catch (Throwable $e) {
    error_log('housingstudy moderation: db error');
    hs_fail(503, '신고 내역을 불러올 수 없습니다.');
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ko"><head><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HousingStudy 신고 검토</title><style>body{font:15px/1.6 system-ui,sans-serif;max-width:850px;margin:20px auto;padding:0 16px;color:#182d3c}article{border:1px solid #dae1e8;border-radius:12px;margin:12px 0;padding:16px}small{color:#687b88}a{color:#008478}</style></head><body>
<h1>게시판 신고 검토</h1><a href="/housingstudy/">게시판으로 돌아가기</a>
<p>신고된 글을 확인하고 필요할 경우 관리자 계정으로 삭제하세요.</p>
<?php foreach ($rows as $row): ?>
<article><small><?= hs_h((string)$row['created_at']) ?> · 신고: <?= hs_h((string)$row['reason']) ?></small><br>
<?php if ($row['deleted_at']): ?>삭제된 글<?php else: ?><a href="/housingstudy/?post=<?= (int)$row['post_id'] ?>"><?= hs_h((string)$row['title']) ?></a><?php endif ?>
<br><small>작성자: <?= hs_h((string)$row['author_name']) ?></small></article>
<?php endforeach ?>
<?php if (!$rows): ?><p>아직 신고된 게시글이 없습니다.</p><?php endif ?>
</body></html>