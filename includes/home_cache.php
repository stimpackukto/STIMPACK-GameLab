<?php
declare(strict_types=1);

/**
 * Anonymous home HTML cache only.
 * Never cache authenticated/session-bearing requests or PHP response headers.
 * Cache is stored outside the public document tree and expires after 60 seconds.
 */
function gamelab_home_cache_eligible(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && empty($_GET)
        && empty($_COOKIE)
        && !isset($_SERVER['HTTP_AUTHORIZATION'])
        && !isset($_SERVER['PHP_AUTH_USER']);
}

function gamelab_home_cache_file(): string
{
    $root = dirname(__DIR__);
    $dependencies = [
        '/index.php', '/includes/header.php', '/includes/footer.php',
        '/includes/data.php', '/includes/catalog.php', '/includes/config.php',
        '/assets/css/style.css', '/assets/js/app.js',
        '/data/catalog/games.json', '/data/catalog/apps.json',
        '/data/catalog/tools.json', '/data/catalog/music.json',
    ];
    $versions = [];
    foreach ($dependencies as $relative) {
        $path = $root . $relative;
        $versions[] = $relative . ':' . (is_file($path) ? (string)filemtime($path) . ':' . (string)filesize($path) : '-');
    }
    $key = hash('sha256', implode('|', $versions));
    return $root . '/data/cache/home-anonymous-' . $key . '.html';
}

function gamelab_home_cache_serve(): bool
{
    if (!gamelab_home_cache_eligible()) return false;
    $file = gamelab_home_cache_file();
    if (!is_file($file) || (time() - (int)filemtime($file)) >= 60) return false;
    $html = @file_get_contents($file);
    if (!is_string($html) || !str_ends_with($html, '</html>')) return false;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-GameLab-Home-Cache: HIT');
    echo $html;
    return true;
}

function gamelab_home_cache_begin(): void
{
    if (!gamelab_home_cache_eligible()) return;
    header('Cache-Control: private, no-store');
    header('X-GameLab-Home-Cache: MISS');
    ob_start();
    register_shutdown_function(static function (): void {
        if (ob_get_level() === 0) return;
        $html = ob_get_contents();
        if (!is_string($html) || !str_ends_with(rtrim($html), '</html>')) return;
        if (http_response_code() !== 200) return;
        $last = error_get_last();
        if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR], true)) return;
        $target = gamelab_home_cache_file();
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return;
        $temp = @tempnam($dir, '.home-');
        if ($temp === false) return;
        if (@file_put_contents($temp, $html, LOCK_EX) !== strlen($html)) { @unlink($temp); return; }
        @chmod($temp, 0640);
        if (!@rename($temp, $target)) @unlink($temp);
    });
}
