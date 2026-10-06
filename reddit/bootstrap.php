<?php
declare(strict_types=1);

/**
 * STIMPACK / forum.pe.kr shared Reddit bootstrap.
 * Public code lives in /reddit, secrets live outside the web root.
 */

$siteConfig = dirname(__DIR__) . '/includes/config.php';
if (is_file($siteConfig)) {
    require_once $siteConfig;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$redditPrivateConfigFile = getenv('REDDIT_CONFIG_FILE') ?: '/etc/stimpack/reddit.php';
if (!is_file($redditPrivateConfigFile)) {
    throw new RuntimeException('Reddit private config not found: ' . $redditPrivateConfigFile);
}

/** @var array<string,mixed> $REDDIT_CONFIG */
$REDDIT_CONFIG = require $redditPrivateConfigFile;
if (!is_array($REDDIT_CONFIG)) {
    throw new RuntimeException('Invalid Reddit private config.');
}

require_once __DIR__ . '/reddit_lib.php';
