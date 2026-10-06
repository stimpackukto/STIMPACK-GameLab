<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Referrer-Policy: no-referrer');
reddit_require_admin();

if (!reddit_is_configured()) {
    http_response_code(500);
    echo 'Reddit client is not configured in /etc/stimpack/reddit.php';
    exit;
}

header('Location: ' . reddit_authorize_url(), true, 302);
exit;
