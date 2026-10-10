<?php
declare(strict_types=1);

// STIMPACK GAME LAB -> WoW: issue a one-use entrance ticket.
// Uses the existing shared PHP session; no login or WoW account changes.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
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
$_SESSION['gamelab_wow_entry_ticket'] = $ticket;
$_SESSION['gamelab_wow_entry_issued'] = time();

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Location: /wow/enter.php?ticket=' . rawurlencode($ticket) . '&to=' . rawurlencode($destination), true, 302);
exit;
