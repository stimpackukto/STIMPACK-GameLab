<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/admin.php';

$postId = (int)($_GET['id'] ?? 0);

if ($postId <= 0) {
    http_response_code(400);
    exit('잘못된 글 번호입니다.');
}


$comments = [];
$commentError = '';
$email = gamelab_login_email();
$google = is_array($_SESSION['google_user'] ?? null) ? $_SESSION['google_user'] : [];
$sub = trim((string)($google['sub'] ?? ''));
$identity = $sub !== '' ? $sub : ($email !== '' ? 'email:' . hash('sha256', $email) : '');
$name = trim((string)($google['name'] ?? ''));
if ($name === '' && $email !== '') $name = explode('@', $email)[0];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$email || !$identity) throw new RuntimeException('구글 로그인이 필요합니다.');
        if (!gamelab_verify_csrf((string)($_POST['csrf'] ?? ''))) throw new RuntimeException('요청이 만료되었습니다.');
        $body = trim((string)($_POST['body'] ?? ''));
        $parentId = max(0, (int)($_POST['parent_id'] ?? 0));
        if ($body === '' || mb_strlen($body, 'UTF-8') > 2000) throw new RuntimeException('댓글은 1~2000자로 입력해 주세요.');
        $db = gamelab_db();
        $check = $db->prepare('SELECT id FROM gamelab_community_posts WHERE id = ?');
        $check->execute([$postId]);
        if (!$check->fetchColumn()) throw new RuntimeException('게시글을 찾을 수 없습니다.');
        if ($parentId) {
            $check = $db->prepare('SELECT id, parent_id FROM gamelab_community_comments WHERE id = ? AND post_id = ?');
            $check->execute([$parentId, $postId]);
            $parent = $check->fetch();
            if (!$parent) throw new RuntimeException('답글 대상이 없습니다.');
            if ((int)$parent['parent_id'] > 0) $parentId = (int)$parent['parent_id'];
        }
        $stmt = $db->prepare('INSERT INTO gamelab_community_comments (post_id,parent_id,google_sub,user_name,user_email,is_admin,content,created_at) VALUES (?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$postId, $parentId, $identity, $name, $email, gamelab_is_admin() ? 1 : 0, $body]);
        header('Location: /community/view.php?id=' . $postId . '#comments', true, 303);
        exit;
    } catch (RuntimeException $e) {
        $commentError = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[community-comment] ' . $e->getMessage());
        $commentError = '댓글 저장에 실패했습니다. 댓글 테이블 설정을 확인해 주세요.';
    }
}

$post = null;
$error = '';

try {
    $pdo = gamelab_db();

    $updateStmt = $pdo->prepare(
        'UPDATE gamelab_community_posts
         SET view_count = view_count + 1
         WHERE id = :id'
    );
    $updateStmt->execute([
        ':id' => $postId,
    ]);

    $stmt = $pdo->prepare(
        'SELECT
            id,
            category,
            title,
            content,
            user_name,
            is_admin,
            view_count,
            created_at,
            updated_at
         FROM gamelab_community_posts
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $postId,
    ]);

    $post = $stmt->fetch();

    if (!$post) {
        http_response_code(404);
        $error = '글을 찾을 수 없습니다.';
    }

    if ($post) {
        try {
            $stmt = $pdo->prepare('SELECT id,parent_id,user_name,is_admin,content,created_at FROM gamelab_community_comments WHERE post_id = ? ORDER BY COALESCE(NULLIF(parent_id,0),id), id');
            $stmt->execute([$postId]);
            $comments = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[community-comments-list] ' . $e->getMessage());
            $commentError = $commentError ?: '댓글 목록을 불러오지 못했습니다. 댓글 테이블 설정을 확인해 주세요.';
        }
    }


} catch (Throwable $e) {
    http_response_code(500);
    $error = '커뮤니티 글을 불러올 수 없습니다.';
}

function community_render_post_content(string $content): string
{
    $pattern = '~\\[\\[community-image:(/uploads/community/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(?:jpg|png|webp))\\]\\]~i';

    $result = '';
    $offset = 0;

    if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($matches[0] as $index => $match) {
            $token = (string)$match[0];
            $position = (int)$match[1];
            $path = (string)$matches[1][$index][0];

            $before = substr($content, $offset, $position - $offset);
            $result .= nl2br(e($before));

            $safePath = e($path);
            $result .= '<figure class="community-post-image">'
                . '<a href="' . $safePath . '" target="_blank" rel="noopener noreferrer">'
                . '<img src="' . $safePath . '" alt="첨부 이미지" loading="lazy">'
                . '</a>'
                . '</figure>';

            $offset = $position + strlen($token);
        }
    }

    $result .= nl2br(e(substr($content, $offset)));

    return $result;
}

$pageTitle = $post
    ? (string)$post['title']
    : '커뮤니티 글';

require dirname(__DIR__) . '/includes/header.php';
?>

<section class="subhero community-hero">
    <div class="container">
        <p class="section-kicker">COMMUNITY</p>
        <h1>STIMPACK LAB 커뮤니티</h1>
        <p>게임과 앱, 개발과 테스트 이야기를 함께 나누는 공간입니다.</p>
    </div>
</section>

<section class="section community-view-section">
    <div class="container">
        <div class="community-view-wrap">

            <?php if ($error !== '' || !$post): ?>

                <div class="notice-box">
                    <b><?= e($error !== '' ? $error : '글을 찾을 수 없습니다.') ?></b>
                    <p>
                        <a href="/community/">커뮤니티 목록으로 돌아가기</a>
                    </p>
                </div>

            <?php else: ?>

                <article class="community-post-card">

                    <div class="community-post-top">
                        <span class="community-category">
                            <?= e((string)$post['category']) ?>
                        </span>

                        <h1><?= e((string)$post['title']) ?></h1>

                        <div class="community-post-meta">
                            <span>
                                작성자
                                <strong>
                                    <?= !empty($post['is_admin'])
                                        ? '개발자'
                                        : e((string)$post['user_name'])
                                    ?>
                                </strong>
                            </span>

                            <span>
                                작성일
                                <strong>
                                    <?= e(date(
                                        'Y.m.d H:i',
                                        strtotime((string)$post['created_at'])
                                    )) ?>
                                </strong>
                            </span>

                            <span>
                                조회
                                <strong>
                                    <?= number_format((int)$post['view_count']) ?>
                                </strong>
                            </span>
                        </div>
                    </div>

                    <div class="community-post-content">
                        <?= community_render_post_content((string)$post['content']) ?>
                    </div>


                    <section id="comments" class="community-comments">
                        <h2>댓글 (<?= count($comments) ?>)</h2>
                        <?php if ($commentError): ?><p role="alert" style="color:#ffaaaa"><?= e($commentError) ?></p><?php endif; ?>
                        <?php if (!$comments): ?><p>아직 댓글이 없습니다.</p><?php endif; ?>
                        <?php foreach ($comments as $c): ?>
                            <div class="community-comment <?= (int)$c['parent_id'] ? 'reply' : '' ?>">
                                <div class="community-comment-meta">
                                    <b><?= !empty($c['is_admin']) ? '개발자' : e((string)$c['user_name']) ?></b>
                                    <time><?= e(date('Y.m.d H:i', strtotime((string)$c['created_at']))) ?></time>
                                </div>
                                <p><?= nl2br(e((string)$c['content'])) ?></p>
                                <?php if ($email): ?>
                                    <button type="button" class="community-reply-button" data-id="<?= (int)((int)$c['parent_id'] ?: $c['id']) ?>" data-name="<?= e((string)$c['user_name']) ?>">↳ 답글</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($email): ?>
                            <form method="post" action="/community/view.php?id=<?= $postId ?>#comments" class="community-comment-form">
                                <h3>댓글 작성</h3>
                                <input type="hidden" name="csrf" value="<?= e(gamelab_csrf_token()) ?>">
                                <input type="hidden" name="parent_id" id="comment-parent" value="0">
                                <p id="reply-indicator" hidden><span id="reply-label"></span> <button type="button" id="reply-cancel">취소</button></p>
                                <textarea name="body" id="comment-body" maxlength="2000" placeholder="댓글을 입력하세요" required></textarea>
                                <button type="submit" class="button primary">등록</button>
                            </form>
                        <?php else: ?>
                            <p><a href="/auth/google_login.php">구글 로그인</a> 후 댓글을 작성할 수 있습니다.</p>
                        <?php endif; ?>
                    </section>

                    <div class="community-post-actions">
                        <a
                            class="button secondary"
                            href="/community/"
                        >
                            목록
                        </a>

                        <a
                            class="button primary"
                            href="/community/write.php"
                        >
                            새글 +
                        </a>
                    </div>

                </article>

            <?php endif; ?>

        </div>
    </div>
</section>


<style>
.community-comments{margin-top:32px;padding-top:24px;border-top:1px solid #36536b}
.community-comments h2{font-size:20px;margin:0 0 16px}
.community-comment{padding:15px 0;border-top:1px solid #29445b;overflow-wrap:anywhere}
.community-comment.reply{margin-left:28px;padding-left:15px;border-left:2px solid #208fd1}
.community-comment-meta{display:flex;gap:12px;flex-wrap:wrap;font-size:13px}
.community-comment-meta time{opacity:.65}
.community-comment p{line-height:1.65;margin:10px 0}
.community-reply-button,#reply-cancel{color:#69bbf1;background:none;border:0;cursor:pointer}
.community-comment-form{padding-top:18px;margin-top:18px;border-top:1px solid #36536b}
.community-comment-form h3{font-size:17px}
.community-comment-form textarea{display:block;width:100%;box-sizing:border-box;min-height:100px;padding:12px;color:#fff;background:#0b2134;border:1px solid #496680;border-radius:8px;margin:12px 0}
@media(max-width:600px){.community-comment.reply{margin-left:12px}}
</style>
<script>
document.querySelectorAll('.community-reply-button').forEach(button => button.addEventListener('click', () => {
  document.getElementById('comment-parent').value = button.dataset.id;
  document.getElementById('reply-label').textContent = button.dataset.name + '님에게 답글 작성 중';
  document.getElementById('reply-indicator').hidden = false;
  document.getElementById('comment-body').focus();
}));
document.getElementById('reply-cancel')?.addEventListener('click', () => {
  document.getElementById('comment-parent').value = 0;
  document.getElementById('reply-indicator').hidden = true;
});
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
