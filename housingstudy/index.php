<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

$me = hs_me();
$isAdmin = hs_admin();
$categories = hs_categories();
$category = (string)($_GET['category'] ?? '전체');
if ($category !== '전체' && !in_array($category, $categories, true)) $category = '전체';
$postId = max(0, (int)($_GET['post'] ?? 0));
$compose = isset($_GET['compose']);
$page = min(1000, max(1, (int)($_GET['page'] ?? 1)));
$error = '';
$posts = [];
$post = null;
$comments = [];
$blocked = [];
$total = 0;
try {
    $db = hs_db();
    $blocked = hs_blocks($db, $me);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $actor = hs_require_write();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $selected = trim((string)($_POST['category'] ?? '자유'));
            if (!in_array($selected, $categories, true)) hs_fail(400, '게시판 분류를 선택하세요.');
            $title = hs_limit(trim((string)($_POST['title'] ?? '')), 160);
            $body = hs_limit(trim((string)($_POST['body'] ?? '')), 10000);
            if (mb_strlen($title) < 3 || mb_strlen($body) < 5) hs_fail(400, '제목 3자, 본문 5자 이상 입력하세요.');
            $url = hs_clean_url((string)($_POST['resource_url'] ?? ''));
            hs_rate_limit($db, 'housingstudy_posts', $actor['hash'], 30);
            $upload = hs_upload();
            try {
                $stmt = $db->prepare('INSERT INTO housingstudy_posts
                    (author_hash,author_name,category,title,body,resource_url,attachment_token,attachment_name,attachment_mime)
                    VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$actor['hash'], $actor['name'], $selected, $title, $body, $url,
                    $upload['token'] ?? null, $upload['name'] ?? null, $upload['mime'] ?? null]);
                $id = (int)$db->lastInsertId();
            } catch (Throwable $e) {
                if ($upload) @unlink($upload['disk']);
                throw $e;
            }
            hs_redirect('/housingstudy/?post=' . $id);
        }
        if ($action === 'comment') {
            $id = (int)($_POST['post_id'] ?? 0);
            $target = hs_post($db, $id);
            if (!$target) hs_fail(404, '글을 찾을 수 없습니다.');
            $body = hs_limit(trim((string)($_POST['body'] ?? '')), 2000);
            if (mb_strlen($body) < 2) hs_fail(400, '댓글을 2자 이상 입력하세요.');
            hs_rate_limit($db, 'housingstudy_comments', $actor['hash'], 10);
            $stmt = $db->prepare('INSERT INTO housingstudy_comments (post_id,author_hash,author_name,body) VALUES (?,?,?,?)');
            $stmt->execute([$id, $actor['hash'], $actor['name'], $body]);
            hs_redirect('/housingstudy/?post=' . $id . '#comments');
        }
        if ($action === 'delete') {
            $id = (int)($_POST['post_id'] ?? 0);
            $target = hs_post($db, $id);
            if (!$target) hs_fail(404, '글을 찾을 수 없습니다.');
            if (!$isAdmin && !hash_equals($target['author_hash'], $actor['hash'])) hs_fail(403, '삭제 권한이 없습니다.');
            $stmt = $db->prepare('UPDATE housingstudy_posts SET deleted_at=NOW() WHERE id=?');
            $stmt->execute([$id]);
            if ($target['attachment_token']) {
                foreach (['.pdf', '.jpg', '.png'] as $ext) {
                    @unlink(HS_UPLOAD_DIR . '/' . $target['attachment_token'] . $ext);
                }
            }
            hs_redirect('/housingstudy/');
        }
        if ($action === 'delete_comment') {
            $id = (int)($_POST['comment_id'] ?? 0);
            $stmt = $db->prepare('SELECT id, post_id, author_hash FROM housingstudy_comments WHERE id=? AND deleted_at IS NULL');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) hs_fail(404, '댓글을 찾을 수 없습니다.');
            if (!$isAdmin && !hash_equals((string)$target['author_hash'], $actor['hash'])) hs_fail(403, '삭제 권한이 없습니다.');
            $db->prepare('UPDATE housingstudy_comments SET deleted_at=NOW() WHERE id=?')->execute([$id]);
            hs_redirect('/housingstudy/?post=' . (int)$target['post_id'] . '#comments');
        }
        if ($action === 'report') {
            $id = (int)($_POST['post_id'] ?? 0);
            $target = hs_post($db, $id);
            if (!$target) hs_fail(404, '글을 찾을 수 없습니다.');
            $reason = (string)($_POST['reason'] ?? '');
            if (!in_array($reason, ['스팸·광고', '욕설·혐오', '개인정보 노출', '저작권 침해', '기타'], true)) hs_fail(400, '신고 사유를 선택하세요.');
            $stmt = $db->prepare('INSERT IGNORE INTO housingstudy_reports (post_id,reporter_hash,reason) VALUES (?,?,?)');
            $stmt->execute([$id, $actor['hash'], $reason]);
            hs_redirect('/housingstudy/?post=' . $id . '&reported=1');
        }
        if ($action === 'block') {
            $id = (int)($_POST['post_id'] ?? 0);
            $target = hs_post($db, $id);
            if (!$target) hs_fail(404, '글을 찾을 수 없습니다.');
            if (!hash_equals($target['author_hash'], $actor['hash'])) {
                $db->prepare('INSERT IGNORE INTO housingstudy_blocks (blocker_hash, blocked_hash) VALUES (?,?)')
                    ->execute([$actor['hash'], $target['author_hash']]);
            }
            hs_redirect('/housingstudy/?blocked=1');
        }
        hs_fail(400, '알 수 없는 요청입니다.');
    }

    if ($postId > 0) {
        $post = hs_post($db, $postId);
        if ($post && isset($blocked[$post['author_hash']])) $post = null;
        if ($post) {
            $stmt = $db->prepare('SELECT id, author_hash, author_name, body, created_at
                FROM housingstudy_comments WHERE post_id=? AND deleted_at IS NULL ORDER BY id ASC LIMIT 200');
            $stmt->execute([$postId]);
            $comments = array_values(array_filter($stmt->fetchAll(), static fn($x) =>
                !isset($blocked[$x['author_hash']])));
        }
    } else if (!$compose) {
        if ($category === '전체') {
            $total = (int)$db->query('SELECT COUNT(*) FROM housingstudy_posts WHERE deleted_at IS NULL')->fetchColumn();
            $stmt = $db->prepare('SELECT id,author_hash,author_name,category,title,created_at,
                (SELECT COUNT(*) FROM housingstudy_comments c WHERE c.post_id=p.id AND c.deleted_at IS NULL) AS replies
                FROM housingstudy_posts p WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 20 OFFSET ?');
            $stmt->bindValue(1, ($page-1)*20, PDO::PARAM_INT);
        } else {
            $count = $db->prepare('SELECT COUNT(*) FROM housingstudy_posts WHERE deleted_at IS NULL AND category=?');
            $count->execute([$category]);
            $total = (int)$count->fetchColumn();
            $stmt = $db->prepare('SELECT id,author_hash,author_name,category,title,created_at,
                (SELECT COUNT(*) FROM housingstudy_comments c WHERE c.post_id=p.id AND c.deleted_at IS NULL) AS replies
                FROM housingstudy_posts p WHERE deleted_at IS NULL AND category=? ORDER BY id DESC LIMIT 20 OFFSET ?');
            $stmt->bindValue(1, $category, PDO::PARAM_STR);
            $stmt->bindValue(2, ($page-1)*20, PDO::PARAM_INT);
        }
        $stmt->execute();
        $posts = array_values(array_filter($stmt->fetchAll(), static fn($x) =>
            !isset($blocked[$x['author_hash']])));
    }
} catch (PDOException $e) {
    error_log('housingstudy board db unavailable: ' . $e->getCode());
    $error = '게시판 데이터베이스 준비가 필요해요. 관리자에게 문의하세요.';
} catch (Throwable $e) {
    error_log('housingstudy board: ' . $e->getMessage());
    $error = '게시판을 불러오지 못했어요. 잠시 후 다시 시도하세요.';
}
$csrf = hs_csrf();
$login = '/auth/google_login.php?return=%2Fhousingstudy%2F';
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>HousingStudy 학습자 게시판</title>
<style>
:root{color-scheme:light;--bg:#f5f7fa;--ink:#172636;--muted:#738090;--line:#e5eaf0;--teal:#148b81}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font:15px/1.55 system-ui,-apple-system,sans-serif;color:var(--ink)}
a{color:inherit;text-decoration:none}main{max-width:850px;margin:auto;padding:20px 16px 60px}
header{background:#1b394a;color:white;padding:19px 18px}header .wrap{max-width:850px;margin:auto;display:flex;justify-content:space-between;align-items:center;gap:12px}
header small{color:#b6e9d9;display:block}h1{font-size:23px;margin:1px 0}h2{font-size:20px;margin:0 0 14px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;margin:12px 0}
.note{font-size:13px;color:var(--muted)}.toolbar,.categories{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:17px 0}
.btn,.pill{display:inline-block;border:1px solid #d6e2e4;background:white;color:var(--ink);padding:9px 13px;border-radius:10px;font-weight:650;font-size:14px;cursor:pointer;font-family:inherit}
.btn.primary{background:var(--teal);color:white;border-color:var(--teal)}.pill.active{background:#e2f7ef;color:#00685d;border-color:#b2e2d6}
.card-row{padding:14px 2px;border-bottom:1px solid var(--line)}.card-row:last-child{border:0}.card-row b{display:block;font-size:16px}
.meta{font-size:12px;color:var(--muted);margin:4px 0}.body{white-space:pre-wrap;overflow-wrap:anywhere;font-size:15px}
label{font-size:14px;font-weight:700;display:block;margin:14px 0 6px}
input[type=text],input[type=url],textarea,select{width:100%;padding:13px;border-radius:10px;border:1px solid #cfd9e0;background:white;font:inherit;color:var(--ink)}
textarea{min-height:175px;resize:vertical}textarea.reply{min-height:90px}
.info{background:#e8f7f1;padding:12px;border-radius:12px;color:#195a50}.warn{background:#fff5e4;padding:12px;border-radius:12px;color:#8a5200}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}button.danger{color:#b83232}
details{margin-top:15px}summary{cursor:pointer;color:var(--muted)}.attachment{background:#f0f5fa;padding:12px;border-radius:10px;display:block;overflow-wrap:anywhere}
footer{color:var(--muted);font-size:12px;margin-top:30px}hr{border:0;border-top:1px solid var(--line);margin:18px 0}
@media(max-width:500px){header h1{font-size:19px}header .btn{font-size:12px;padding:8px}main{padding:12px}.card{padding:15px}}
</style>
</head><body>
<header><div class="wrap"><div><small>STIMPACK · STUDY</small><h1>학습자 게시판</h1></div>
<?php if ($me): ?>
<div style="text-align:right"><small><?= hs_h($me['name']) ?></small><a class="btn" href="/housingstudy/">게시판</a></div>
<?php else: ?>
<a class="btn" href="<?= hs_h($login) ?>">구글 로그인</a>
<?php endif ?>
</div></header>
<main>
<div class="note">주택관리사보 1차 · 질문과 학습자료, 공부 경험을 함께 나누는 곳</div>
<?php if ($error): ?>
<div class="card warn"><?= hs_h($error) ?></div>
<?php elseif ($compose): ?>
<div class="toolbar"><a href="/housingstudy/">〈 목록</a></div>
<div class="card"><h2>새 글 쓰기</h2>
<?php if (!$me): ?><p class="warn">글을 쓰려면 <a href="<?= hs_h($login) ?>">구글 로그인</a>이 필요해요.</p>
<?php else: ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="create">
<label for="category">분류</label><select name="category" id="category" required>
<?php foreach ($categories as $cat): ?><option><?= hs_h($cat) ?></option><?php endforeach ?></select>
<label for="title">제목</label><input id="title" name="title" type="text" minlength="3" maxlength="160" required placeholder="내용을 알아보기 쉬운 제목">
<label for="body">내용</label><textarea id="body" name="body" maxlength="10000" minlength="5" required placeholder="질문이나 자료 설명을 입력하세요"></textarea>
<label for="resource_url">참고자료 링크 (선택)</label><input id="resource_url" name="resource_url" type="url" maxlength="2000" placeholder="https://">
<label for="attachment">자료 첨부 (선택 · PDF/JPG/PNG 최대 5MB)</label><input id="attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
<p class="note">타인의 저작물·개인정보를 허락 없이 올리지 마세요. 구글 이메일은 게시판에 공개되지 않습니다.</p>
<button type="submit" class="btn primary">등록하기</button></form>
<?php endif ?></div>
<?php elseif ($postId > 0): ?>
<div class="toolbar"><a href="/housingstudy/">〈 목록</a></div>
<?php if (!$post): ?><div class="card warn">삭제되었거나 볼 수 없는 글이에요.</div>
<?php else: ?>
<article class="card"><div class="meta"><?= hs_h($post['category']) ?> · <?= hs_h($post['author_name']) ?> · <?= hs_h($post['created_at']) ?></div>
<h2><?= hs_h($post['title']) ?></h2><div class="body"><?= hs_h($post['body']) ?></div>
<?php if ($post['resource_url']): ?><p><a href="<?= hs_h($post['resource_url']) ?>" target="_blank" rel="noopener noreferrer" class="attachment">↗ 참고자료 링크 열기</a></p><?php endif ?>
<?php if ($post['attachment_token']): ?><p><a href="/housingstudy/download.php?id=<?= (int)$post['id'] ?>" class="attachment">↓ 첨부파일: <?= hs_h($post['attachment_name']) ?></a></p><?php endif ?>
<?php if ($me): ?>
<div class="actions">
<?php if ($isAdmin || hash_equals($post['author_hash'], $me['hash'])): ?>
<form method="post" onsubmit="return confirm('글을 삭제할까요?')"><input type="hidden" name="csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><button class="btn danger">글 삭제</button></form>
<?php else: ?>
<form method="post" onsubmit="return confirm('이 작성자의 글을 숨길까요?')"><input type="hidden" name="csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="block"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><button class="btn">작성자 차단</button></form>
<?php endif ?>
</div>
<details><summary>이 글 신고하기</summary>
<?php if (isset($_GET['reported'])): ?><p class="info">신고가 접수됐어요.</p><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="report"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
<label for="reason">신고 사유</label><select name="reason" id="reason"><?php foreach (['스팸·광고','욕설·혐오','개인정보 노출','저작권 침해','기타'] as $why): ?><option><?= hs_h($why) ?></option><?php endforeach ?></select>
<button class="btn">신고 접수</button></form></details>
<?php endif ?></article>
<div class="card" id="comments"><h2>댓글 <?= count($comments) ?></h2>
<?php foreach ($comments as $reply): ?>
<div class="card-row"><div class="meta"><?= hs_h($reply['author_name']) ?> · <?= hs_h($reply['created_at']) ?></div>
<div class="body"><?= hs_h($reply['body']) ?></div>
<?php if ($me && ($isAdmin || hash_equals($reply['author_hash'], $me['hash']))): ?>
<form method="post" onsubmit="return confirm('댓글을 삭제할까요?')"><input type="hidden" name="csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="delete_comment"><input type="hidden" name="comment_id" value="<?= (int)$reply['id'] ?>"><button class="btn danger">삭제</button></form>
<?php endif ?></div><?php endforeach ?>
<?php if ($me): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="comment"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
<label for="reply">댓글 작성</label><textarea class="reply" id="reply" name="body" minlength="2" maxlength="2000" required></textarea>
<button class="btn primary" type="submit">댓글 등록</button></form>
<?php else: ?><p class="note"><a href="<?= hs_h($login) ?>">구글 로그인</a>하면 댓글을 작성할 수 있어요.</p><?php endif ?>
</div>
<?php endif ?>
<?php else: ?>
<div class="toolbar"><h2 style="flex:1;margin:0">전체 게시글</h2>
<a class="btn primary" href="<?= $me ? '/housingstudy/?compose=1' : hs_h($login) ?>">＋ 글쓰기</a></div>
<nav class="categories"><?php foreach (array_merge(['전체'], $categories) as $cat): ?>
<a class="pill <?= $category === $cat ? 'active' : '' ?>" href="/housingstudy/?category=<?= rawurlencode($cat) ?>"><?= hs_h($cat) ?></a>
<?php endforeach ?></nav>
<?php if (isset($_GET['blocked'])): ?><p class="info">해당 작성자의 글을 숨겼어요.</p><?php endif ?>
<section class="card">
<?php if (!$posts): ?><p class="note">등록된 글이 없어요. 첫 게시글을 작성해 보세요.</p><?php endif ?>
<?php foreach ($posts as $item): ?>
<div class="card-row"><div class="meta"><?= hs_h($item['category']) ?> · <?= hs_h($item['author_name']) ?> · <?= hs_h($item['created_at']) ?></div>
<a href="/housingstudy/?post=<?= (int)$item['id'] ?>"><b><?= hs_h($item['title']) ?></b></a>
<div class="note">댓글 <?= (int)$item['replies'] ?></div></div>
<?php endforeach ?>
</section>
<div class="toolbar">
<?php if ($page > 1): ?><a class="btn" href="?category=<?= rawurlencode($category) ?>&page=<?= $page-1 ?>">〈 이전</a><?php endif ?>
<span class="note"><?= $page ?> 페이지 · 총 <?= $total ?>개 글</span>
<?php if ($page*20 < $total): ?><a class="btn" href="?category=<?= rawurlencode($category) ?>&page=<?= $page+1 ?>">다음 〉</a><?php endif ?>
</div>
<?php endif ?>
<footer>글·댓글은 구글 계정으로 로그인한 학습자만 작성할 수 있어요. 이메일 주소는 게시판에 공개되지 않으며, 신고된 콘텐츠는 관리자가 검토할 수 있습니다. 본 게시판은 Q-Net 공식 서비스가 아닙니다.</footer>
</main></body></html>
