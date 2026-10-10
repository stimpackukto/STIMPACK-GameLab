<?php
declare(strict_types=1);

// Shared-server, same-origin bridge. Never accept account IDs from the browser.
require_once __DIR__ . '/../includes/db.php';

function gamelab_wow_link_for_google(string $sub): ?array {
    if ($sub === '') return null;
    $stmt = gamelab_db()->prepare('SELECT wow_account_id, google_email FROM gamelab_wow_google_links WHERE google_sub = ? LIMIT 1');
    $stmt->execute([$sub]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function gamelab_wow_session_login(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    $g = $_SESSION['google_user'] ?? null;
    if (!is_array($g) || trim((string)($g['sub'] ?? '')) === '') return false;
    $link = gamelab_wow_link_for_google((string)$g['sub']);
    if (!$link) return false;
    $id = (int)$link['wow_account_id'];
    if ($id < 1) return false;
    // A pre-existing, different WoW login must never be overwritten.
    $existing = (int)($_SESSION['account']['id'] ?? 0);
    if ($existing > 0 && $existing !== $id) return false;
    if ($existing === $id) return true;

    // Reuse the operational WoW connection; do not duplicate credentials.
    $wowConfig = dirname(__DIR__) . '/wow/includes/config.php';
    if (!is_file($wowConfig)) return false;
    require_once $wowConfig;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;
    $stmt = $conn->prepare('SELECT id, username, locked FROM auth.account WHERE id = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (int)($row['locked'] ?? 0) !== 0) return false;
    session_regenerate_id(true);
    $_SESSION['account'] = ['id' => (int)$row['id'], 'username' => (string)$row['username'], 'login_at' => time(), 'login_source' => 'gamelab_google'];
    return true;
}
