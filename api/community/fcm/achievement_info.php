<?php
/*
 * Custom Update - 2026-07-27
 * Read localized achievement names/descriptions directly from TrinityCore 3.3.5a Achievement.dbc.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function achievement_json(bool $success, string $message, array $extra = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'success' => $success,
        'ok' => $success ? 1 : 0,
        'message' => $message,
        'msg' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function achievement_u32(string $data, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($data)) {
        return 0;
    }

    $value = unpack('Vvalue', substr($data, $offset, 4));
    return is_array($value) ? (int)($value['value'] ?? 0) : 0;
}

function achievement_dbc_string(string $stringBlock, int $offset): string
{
    if ($offset <= 0 || $offset >= strlen($stringBlock)) {
        return '';
    }

    $end = strpos($stringBlock, "\0", $offset);
    if ($end === false) {
        $end = strlen($stringBlock);
    }

    $value = trim(substr($stringBlock, $offset, $end - $offset));
    if ($value === '') {
        return '';
    }

    // Client DBC strings are UTF-8. Drop invalid byte sequences instead of
    // breaking the complete JSON response when a damaged file is encountered.
    if (function_exists('mb_convert_encoding')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    return $value;
}

function achievement_localized_field(
    string $data,
    int $recordOffset,
    int $firstField,
    string $stringBlock
): string {
    // A 3.3.5 localized DBC field has 16 string offsets followed by one flag field.
    // Locale-specific DBC files normally populate only one slot, so the first
    // non-empty offset is the correct localized value.
    for ($i = 0; $i < 16; $i++) {
        $stringOffset = achievement_u32($data, $recordOffset + (($firstField + $i) * 4));
        $value = achievement_dbc_string($stringBlock, $stringOffset);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function achievement_data_dir_from_conf(string $confFile): string
{
    if (!is_file($confFile) || !is_readable($confFile)) {
        return '';
    }

    $content = @file_get_contents($confFile);
    if (!is_string($content) || $content === '') {
        return '';
    }

    if (!preg_match('/^\s*DataDir\s*=\s*["\']?([^"\'\r\n#;]+)["\']?/mi', $content, $match)) {
        return '';
    }

    $path = trim((string)$match[1]);
    if ($path === '') {
        return '';
    }

    if ($path[0] !== '/') {
        $path = dirname($confFile) . '/' . $path;
    }

    $resolved = realpath($path);
    return $resolved !== false ? $resolved : rtrim($path, '/');
}

function achievement_find_dbc(): string
{
    $candidates = [];

    if (defined('ACHIEVEMENT_DBC_FILE')) {
        $candidates[] = (string)constant('ACHIEVEMENT_DBC_FILE');
    }

    $explicitFile = trim((string)getenv('TRINITY_ACHIEVEMENT_DBC'));
    if ($explicitFile !== '') {
        $candidates[] = $explicitFile;
    }

    $dataDirs = [];
    foreach (['TRINITY_DATA_DIR', 'TRINITYCORE_DATA_DIR'] as $envName) {
        $value = trim((string)getenv($envName));
        if ($value !== '') {
            $dataDirs[] = $value;
        }
    }

    $confCandidates = [
        '/etc/trinity/worldserver.conf',
        '/etc/trinitycore/worldserver.conf',
        '/home/trinity/server/etc/worldserver.conf',
        '/home/trinity/etc/worldserver.conf',
        '/opt/trinity/etc/worldserver.conf',
        '/opt/trinitycore/etc/worldserver.conf',
    ];

    foreach (glob('/home/*/server*/etc/worldserver.conf') ?: [] as $path) {
        $confCandidates[] = $path;
    }
    foreach (glob('/home/*/*/etc/worldserver.conf') ?: [] as $path) {
        $confCandidates[] = $path;
    }

    foreach (array_unique($confCandidates) as $confFile) {
        $dataDir = achievement_data_dir_from_conf($confFile);
        if ($dataDir !== '') {
            $dataDirs[] = $dataDir;
        }
    }

    // A project-local data folder is convenient when the web server cannot read
    // the worldserver directory directly.
    $dataDirs[] = __DIR__ . '/data';

    foreach (array_unique($dataDirs) as $dataDir) {
        $base = rtrim((string)$dataDir, '/');
        $candidates[] = $base . '/dbc/koKR/Achievement.dbc';
        $candidates[] = $base . '/dbc/Achievement.dbc';
        $candidates[] = $base . '/koKR/Achievement.dbc';
        $candidates[] = $base . '/Achievement.dbc';
    }

    $fixedCandidates = [
        '/home/trinity/server/data/dbc/koKR/Achievement.dbc',
        '/home/trinity/data/dbc/koKR/Achievement.dbc',
        '/opt/trinity/data/dbc/koKR/Achievement.dbc',
        '/opt/trinitycore/data/dbc/koKR/Achievement.dbc',
        '/var/lib/trinity/dbc/koKR/Achievement.dbc',
    ];
    $candidates = array_merge($candidates, $fixedCandidates);

    $globPatterns = [
        '/home/*/server*/data/dbc/koKR/Achievement.dbc',
        '/home/*/*/data/dbc/koKR/Achievement.dbc',
        '/home/*/data/dbc/koKR/Achievement.dbc',
        '/opt/*/data/dbc/koKR/Achievement.dbc',
    ];
    foreach ($globPatterns as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            $candidates[] = $path;
        }
    }

    foreach (array_unique($candidates) as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
            $resolved = realpath($candidate);
            return $resolved !== false ? $resolved : $candidate;
        }
    }

    return '';
}

function achievement_read_record(string $dbcFile, int $achievementId): ?array
{
    $data = @file_get_contents($dbcFile);
    if (!is_string($data) || strlen($data) < 20) {
        throw new RuntimeException('Achievement.dbc 파일을 읽지 못했습니다.');
    }

    if (substr($data, 0, 4) !== 'WDBC') {
        throw new RuntimeException('Achievement.dbc 헤더가 WDBC 형식이 아닙니다.');
    }

    $recordCount = achievement_u32($data, 4);
    $fieldCount = achievement_u32($data, 8);
    $recordSize = achievement_u32($data, 12);
    $stringBlockSize = achievement_u32($data, 16);

    if ($recordCount <= 0 || $fieldCount < 62 || $recordSize < 248 || $stringBlockSize <= 0) {
        throw new RuntimeException('Achievement.dbc 구조가 TrinityCore 3.3.5a 형식과 다릅니다.');
    }

    $recordsOffset = 20;
    $stringBlockOffset = $recordsOffset + ($recordCount * $recordSize);
    if ($stringBlockOffset < 20 || $stringBlockOffset + $stringBlockSize > strlen($data)) {
        throw new RuntimeException('Achievement.dbc 레코드 또는 문자열 영역이 손상되었습니다.');
    }

    $stringBlock = substr($data, $stringBlockOffset, $stringBlockSize);

    for ($index = 0; $index < $recordCount; $index++) {
        $recordOffset = $recordsOffset + ($index * $recordSize);
        $id = achievement_u32($data, $recordOffset);
        if ($id !== $achievementId) {
            continue;
        }

        $name = achievement_localized_field($data, $recordOffset, 4, $stringBlock);
        $description = achievement_localized_field($data, $recordOffset, 21, $stringBlock);
        $points = achievement_u32($data, $recordOffset + (39 * 4));
        $iconId = achievement_u32($data, $recordOffset + (42 * 4));

        return [
            'achievement_id' => $id,
            'name' => $name,
            'description' => $description,
            'points' => $points,
            'icon_id' => $iconId,
        ];
    }

    return null;
}

$achievementId = (int)($_POST['achievement_id'] ?? $_GET['achievement_id'] ?? 0);
if ($achievementId <= 0 || $achievementId > 100000) {
    achievement_json(false, '올바른 achievement_id 값이 필요합니다.', [], 400);
}

$dbcFile = achievement_find_dbc();
if ($dbcFile === '') {
    achievement_json(false, '서버에서 koKR Achievement.dbc 파일을 찾지 못했습니다.', [
        'achievement_id' => $achievementId,
    ], 503);
}

try {
    $record = achievement_read_record($dbcFile, $achievementId);
} catch (Throwable $error) {
    achievement_json(false, $error->getMessage(), [
        'achievement_id' => $achievementId,
    ], 500);
}

if ($record === null || trim((string)$record['name']) === '') {
    achievement_json(false, '해당 업적을 Achievement.dbc에서 찾지 못했습니다.', [
        'achievement_id' => $achievementId,
    ], 404);
}

achievement_json(true, '업적 정보를 불러왔습니다.', array_merge($record, [
    'source' => '서버 koKR Achievement.dbc',
]));