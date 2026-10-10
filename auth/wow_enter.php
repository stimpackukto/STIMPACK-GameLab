<?php
declare(strict_types=1);

// STIMPACK GAME LAB -> WoW: issue a one-use entrance ticket.
// Uses the existing shared PHP session; no login or WoW account changes.
$destinations = [
    'home' => '/wow/',
    'news' => '/wow/home/news',
    'register' => '/wow/auth/register',
];
$destination = (string)($_GET['to'] ?? 'home');
if (!isset($destinations[$destination])) {
    $destination = 'home';
}
$ticket = bin2hex(random_bytes(24));
setcookie('stimpack_wow_entry', $ticket, [
    'expires' => time() + 120,
    'path' => '/wow/enter.php',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Location: /wow/enter.php?ticket=' . rawurlencode($ticket) . '&to=' . rawurlencode($destination), true, 302);
exit;
