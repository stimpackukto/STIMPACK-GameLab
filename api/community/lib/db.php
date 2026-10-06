<?php
// /api/community/lib/db.php

// 앱 API 전용 DB 헬퍼.
// 기존 홈페이지 파일은 수정하지 않고, 앱 API 안에서만 사용한다.

declare(strict_types=1);

require_once __DIR__ . '/response.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function community_app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function db_conn(?string $database = null): mysqli
{
    static $pool = [];
    $config = community_app_config();
    $db = $database ?: $config['db']['app'];
    if (isset($pool[$db])) {
        return $pool[$db];
    }

    $conn = new mysqli(
        $config['db']['host'],
        $config['db']['user'],
        $config['db']['pass'],
        $db,
        (int)($config['db']['port'] ?? 3306)
    );
    $conn->set_charset((string)($config['db']['charset'] ?? 'utf8mb4'));
    if (!empty($config['db']['sql_mode'])) {
        $conn->query("SET sql_mode = '" . $conn->real_escape_string((string)$config['db']['sql_mode']) . "'");
    }
    $pool[$db] = $conn;
    return $conn;
}

function db_name(string $key): string
{
    $config = community_app_config();
    return $config['db'][$key] ?? $key;
}

function table_exists(mysqli $db, string $table): bool
{
    try {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_row();
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(mysqli $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare('SHOW COLUMNS FROM `' . $db->real_escape_string($table) . '` LIKE ?');
        $stmt->bind_param('s', $column);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    } catch (Throwable $e) {
        return false;
    }
}

function fetch_all_safe(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    try {
        if ($types === '') {
            $res = $db->query($sql);
            return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt = $db->prepare($sql);
        if ($params) {
            $refs = [];
            foreach ($params as $k => $_) {
                $refs[$k] = &$params[$k];
            }
            $stmt->bind_param($types, ...$refs);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function fetch_one_safe(mysqli $db, string $sql, string $types = '', array $params = []): ?array
{
    $rows = fetch_all_safe($db, $sql, $types, $params);
    return $rows[0] ?? null;
}
