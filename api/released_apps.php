<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=60');
header('Access-Control-Allow-Origin: *');

function released_apps_absolute_url(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    return 'https://stimpack.pe.kr' . $path;
}

try {
    $apps = catalog_load('app');

    $released = [];

    foreach ($apps as $app) {
        if (empty($app['released'])) {
            continue;
        }

        $packageId = trim((string)($app['id'] ?? ''));
        $playUrl = trim((string)($app['url'] ?? ''));

        if ($packageId === '' || $playUrl === '') {
            continue;
        }

        $releasedAt = trim((string)($app['released_at'] ?? ''));

        if ($releasedAt === '') {
            $releasedAt = trim(
                (string)(
                    $app['updated_at']
                    ?? $app['created_at']
                    ?? ''
                )
            );
        }

        $released[] = [
            'package_id' => $packageId,
            'title' => trim((string)($app['title'] ?? '')),
            'description' => trim((string)($app['description'] ?? '')),
            'purpose' => trim((string)($app['purpose'] ?? '')),
            'status' => trim((string)($app['status'] ?? '')),
            'released' => true,
            'released_at' => $releasedAt,
            'image_url' => released_apps_absolute_url(
                (string)($app['image'] ?? '')
            ),
            'play_url' => $playUrl,
        ];
    }

    usort(
        $released,
        static function (array $a, array $b): int {
            return strcmp(
                (string)($b['released_at'] ?? ''),
                (string)($a['released_at'] ?? '')
            );
        }
    );

    echo json_encode(
        [
            'ok' => true,
            'count' => count($released),
            'generated_at' => date(DATE_ATOM),
            'apps' => $released,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode(
        [
            'ok' => false,
            'count' => 0,
            'apps' => [],
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
}
