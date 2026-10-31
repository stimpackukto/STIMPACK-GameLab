<?php
declare(strict_types=1);

// CLI-only, idempotent migration of pre-existing JSON music comments.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/db.php';

$legacy = __DIR__ . '/data/comments.php';
$prefix = "<?php http_response_code(404); exit; ?>\n";
if (!is_file($legacy)) { echo "No legacy comments file. Nothing to migrate.\n"; exit(0); }
$raw = file_get_contents($legacy);
if (!is_string($raw) || !str_starts_with($raw, $prefix)) {
    fwrite(STDERR, "Legacy file format not recognized. No changes made.\n");
    exit(1);
}
$rows = json_decode(substr($raw, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($rows)) { fwrite(STDERR, "Invalid comments list.\n"); exit(1); }

$db = gamelab_db();
$insert = $db->prepare(
    'INSERT IGNORE INTO gamelab_music_comments (id, track_id, body, name, owner, created_at)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$added = 0; $skipped = 0;
foreach ($rows as $row) {
    if (!is_array($row)) { ++$skipped; continue; }
    $id = (string)($row['id'] ?? '');
    $track = (string)($row['track_id'] ?? '');
    $body = (string)($row['body'] ?? '');
    $name = (string)($row['name'] ?? '');
    $owner = (string)($row['owner'] ?? '');
    $created = (string)($row['created'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $id) ||
        !preg_match('/^[a-f0-9]{32}$/', $track) ||
        $body === '' || $name === '' || $owner === '' ||
        !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $created)) {
        ++$skipped; continue;
    }
    $insert->execute([$id, $track, $body, $name, $owner, $created . ':00']);
    $added += $insert->rowCount();
}
echo "Migrated: {$added}, skipped/previously imported: " . (count($rows) - $added) . "\n";
echo "Original legacy file kept unchanged.\n";
