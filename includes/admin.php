<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const GAMELAB_ADMIN_EMAIL = 'esotarsound@gmail.com';

function gamelab_login_email(): string
{
    $email = '';

    if (!empty($_SESSION['google_user']['email'])) {
        $email = (string)$_SESSION['google_user']['email'];
    } elseif (!empty($_SESSION['google_email'])) {
        $email = (string)$_SESSION['google_email'];
    }

    return strtolower(trim($email));
}

function gamelab_is_admin(): bool
{
    return gamelab_login_email() === strtolower(GAMELAB_ADMIN_EMAIL);
}

function gamelab_require_admin(): void
{
    if (!gamelab_is_admin()) {
        http_response_code(403);
        exit('관리자만 사용할 수 있습니다.');
    }
}

function gamelab_csrf_token(): string
{
    if (empty($_SESSION['gamelab_csrf'])) {
        $_SESSION['gamelab_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['gamelab_csrf'];
}

function gamelab_verify_csrf(string $token): bool
{
    return isset($_SESSION['gamelab_csrf'])
        && hash_equals((string)$_SESSION['gamelab_csrf'], $token);
}