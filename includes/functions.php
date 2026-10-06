<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_path(): string
{
    return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
}

function is_active(string $path): bool
{
    $current = current_path();
    if ($path === '/') {
        return $current === '/';
    }
    return str_starts_with($current, $path);
}
