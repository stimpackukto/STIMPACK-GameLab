<?php
declare(strict_types=1);

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

function game_click_file(): string
{
    return dirname(__DIR__) . '/data/game_clicks.json';
}

function game_click_read_counts(): array
{
    $file = game_click_file();

    if (!is_file($file)) {
        return [];
    }

    $fp = @fopen($file, 'r');

    if (!$fp) {
        return [];
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            return [];
        }

        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $counts = [];

        foreach ($decoded as $slug => $count) {
            if (
                is_string($slug)
                && preg_match('/^[a-z0-9_-]+$/', $slug)
            ) {
                $counts[$slug] = max(0, (int)$count);
            }
        }

        return $counts;

    } finally {
        fclose($fp);
    }
}

function game_click_increment(string $slug): int
{
    $file = game_click_file();
    $dir = dirname($file);

    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('카운트 저장 폴더를 만들 수 없습니다.');
    }

    $fp = @fopen($file, 'c+');

    if (!$fp) {
        throw new RuntimeException('카운트 파일을 열 수 없습니다.');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('카운트 파일 잠금에 실패했습니다.');
        }

        rewind($fp);
        $raw = stream_get_contents($fp);

        $counts = [];

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $counts = $decoded;
            }
        }

        $counts[$slug] = max(0, (int)($counts[$slug] ?? 0)) + 1;

        ksort($counts);

        $json = json_encode(
            $counts,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('카운트 JSON 생성에 실패했습니다.');
        }

        ftruncate($fp, 0);
        rewind($fp);

        if (fwrite($fp, $json) === false) {
            throw new RuntimeException('카운트 저장에 실패했습니다.');
        }

        fflush($fp);
        flock($fp, LOCK_UN);

        return (int)$counts[$slug];

    } finally {
        fclose($fp);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    game_click_json(true, [
        'counts' => game_click_read_counts(),
    ]);
}

if ($method !== 'POST') {
    game_click_json(false, ['message' => 'Method Not Allowed'], 405);
}

$slug = strtolower(trim((string)($_POST['slug'] ?? '')));

if (
    $slug === ''
    || !preg_match('/^[a-z0-9_-]{1,80}$/', $slug)
) {
    game_click_json(false, ['message' => '잘못된 게임 ID입니다.'], 400);
}

/*
 * 실제 서버에 존재하는 /games/폴더명만 카운트한다.
 * 임의의 키를 API에 넣어 카운트를 생성하지 못하게 한다.
 */
$gameDir = dirname(__DIR__) . '/games/' . $slug;

if (!is_dir($gameDir)) {
    game_click_json(false, ['message' => '게임을 찾을 수 없습니다.'], 404);
}

try {
    $count = game_click_increment($slug);

    game_click_json(true, [
        'slug' => $slug,
        'count' => $count,
    ]);

} catch (Throwable $e) {
    error_log('GAME_CLICK: ' . $e->getMessage());

    game_click_json(false, [
        'message' => '카운트를 저장하지 못했습니다.',
    ], 500);
}
