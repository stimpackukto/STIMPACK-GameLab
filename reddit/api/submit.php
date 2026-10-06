<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        reddit_json_response(['ok' => false, 'msg' => 'POST 요청만 허용됩니다.'], 405);
    }
    if (!reddit_origin_allowed()) {
        reddit_json_response(['ok' => false, 'msg' => '허용되지 않은 요청 출처입니다.'], 403);
    }

    $csrf = trim((string)($_POST['csrf'] ?? ''));
    if (!reddit_verify_csrf($csrf)) {
        reddit_json_response(['ok' => false, 'msg' => '보안 토큰이 만료되었습니다. 새로고침 후 다시 시도하세요.'], 403);
    }

    $source = trim((string)($_POST['source'] ?? ''));
    $route = reddit_source_config($source);
    $uid = reddit_current_account_id();
    $requireLogin = (bool)($route['require_site_login'] ?? true);
    if ($requireLogin && $uid <= 0) {
        reddit_json_response(['ok' => false, 'msg' => 'GAME LAB 로그인 후 이용할 수 있습니다.'], 401);
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('제목을 입력하세요.');
    if (mb_strlen($title, 'UTF-8') > 280) throw new InvalidArgumentException('제목은 280자 이하로 입력하세요.');

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    reddit_rate_limit($source, $uid, $ip);

    $result = reddit_submit_text($source, $title, $body);

    // Existing board RSS cache purge so the new post can appear promptly.
    $feed = 'https://www.reddit.com/r/' . $result['subreddit'] . '/new/.rss';
    @unlink(sys_get_temp_dir() . '/openplaylab_reddit_' . md5($feed) . '.json');

    reddit_log_post([
        'time' => date(DATE_ATOM),
        'source' => $source,
        'account_id' => $uid,
        'ip_hash' => hash('sha256', $ip . '|' . (string)reddit_cfg('log_salt', 'change-me')),
        'subreddit' => $result['subreddit'],
        'reddit_id' => $result['id'],
        'reddit_url' => $result['url'],
    ]);

    reddit_json_response([
        'ok' => true,
        'msg' => 'Reddit에 게시되었습니다.',
        'url' => $result['url'],
        'subreddit' => $result['subreddit'],
    ]);
} catch (InvalidArgumentException $e) {
    reddit_json_response(['ok' => false, 'msg' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[reddit-submit] ' . $e->getMessage());
    reddit_json_response(['ok' => false, 'msg' => $e->getMessage()], 502);
}
