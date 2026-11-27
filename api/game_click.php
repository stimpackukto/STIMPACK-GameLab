<?php
declare(strict_types=1);

/**
 * Public game launch attempts. No Google login, account or cookie required.
 *
 * Each click is recorded atomically in MySQL. Existing JSON counts are kept
 * as a read-only baseline, so historical totals remain visible after rollout.
 */
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/catalog.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function game_click_json(bool $ok, array $extra = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        array_merge(['ok' => $ok], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** Previously recorded totals: do not discard or overwrite this file. */
function game_click_legacy_counts(): array
{
    $file = dirname(__DIR__) . '/data/game_clicks.json';
    if (!is_file($file)) return [];

    $fp = @fopen($file, 'rb');
    if ($fp === false) return [];
    try {
        if (!flock($fp, LOCK_SH)) return [];
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) return [];

    $counts = [];
    foreach ($decoded as $slug => $count) {
        if (is_string($slug) && preg_match('/^[a-z0-9_-]{1,80}$/D', $slug)) {
            $counts[$slug] = max(0, (int)$count);
        }
    }
    return $counts;
}

/**
 * Validate the requested game against the existing catalog, NOT an assumed
 * lowercase disk path. A registered /games/Baduk/ directory is case-sensitive
 * on Linux even though the counter key is normalized to "baduk".
 */
function game_click_registered(string $slug): bool
{
    foreach (catalog_load('game') as $game) {
        if (!is_array($game)) continue;
        $url = trim((string)($game['url'] ?? ''));
        if ($url === '') continue;
        $parts = parse_url($url);
        if (!is_array($parts)) continue;
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host !== '' && $host !== 'stimpack.pe.kr' && $host !== 'www.stimpack.pe.kr') {
            continue;
        }
        $path = (string)($parts['path'] ?? '');
        if (preg_match('~^/games/([a-zA-Z0-9_-]{1,80})(?:/|$)~', $path, $matches)
            && strtolower($matches[1]) === $slug) {
            return true;
        }
    }
    return false;
}

function game_click_counts(PDO $db): array
{
    $counts = game_click_legacy_counts();
    $stmt = $db->query('SELECT slug, play_count FROM gamelab_game_play_counts');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slug = (string)$row['slug'];
        $counts[$slug] = (int)($counts[$slug] ?? 0) + (int)$row['play_count'];
    }
    ksort($counts);
    return $counts;
}

function game_click_increment(PDO $db, string $slug): int
{
    $stmt = $db->prepare(
        'INSERT INTO gamelab_game_play_counts (slug, play_count) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE play_count = play_count + 1'
    );
    $stmt->execute([$slug]);
    $stmt = $db->prepare('SELECT play_count FROM gamelab_game_play_counts WHERE slug = ?');
    $stmt->execute([$slug]);
    return (int)$stmt->fetchColumn() + (int)(game_click_legacy_counts()[$slug] ?? 0);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST', true);
    game_click_json(false, ['message' => 'Method Not Allowed'], 405);
}

try {
    $db = gamelab_db();

    if ($method === 'GET') {
        game_click_json(true, ['counts' => game_click_counts($db)]);
    }

    $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
    if (!preg_match('/^[a-z0-9_-]{1,80}$/D', $slug)) {
        game_click_json(false, ['message' => '잘못된 게임 ID입니다.'], 400);
    }

    if (!game_click_registered($slug)) {
        game_click_json(false, ['message' => '등록되지 않은 게임입니다.'], 404);
    }

    $count = game_click_increment($db, $slug);
    game_click_json(true, ['slug' => $slug, 'count' => $count]);
} catch (Throwable $error) {
    error_log('[GAME LAB GAME CLICK] ' . get_class($error) . ': ' . $error->getMessage());
    game_click_json(false, ['message' => '게임 플레이 집계 DB를 확인해 주세요.'], 503);
}
