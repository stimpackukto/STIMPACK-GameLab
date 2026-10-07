<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

$postId = (int)($_GET['id'] ?? 0);

if ($postId <= 0) {
    http_response_code(400);
    exit('잘못된 글 번호입니다.');
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

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
