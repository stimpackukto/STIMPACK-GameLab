<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$store = reddit_read_token_store();
reddit_json_response([
    'ok' => true,
    'configured' => reddit_is_configured(),
    'connected' => !empty($store['refresh_token']),
    'username' => (string)($store['username'] ?? ''),
    'csrf' => reddit_csrf_token(),
    'site_logged_in' => reddit_current_account_id() > 0,
]);
