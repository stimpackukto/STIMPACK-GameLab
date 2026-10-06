<?php
// /api/community/app_api_debug.php
// 설치 직후 500 원인 확인용. 운영 확인 후 삭제해도 된다.

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';

$data = [
    'php_version' => PHP_VERSION,
    'gmp_loaded' => extension_loaded('gmp'),
    'mysqli_loaded' => extension_loaded('mysqli'),
    'openssl_loaded' => extension_loaded('openssl'),
    'config_loaded' => true,
    'db' => [],
];

foreach (['auth', 'characters', 'world', 'app'] as $key) {
    try {
        $name = db_name($key);
        $conn = db_conn($name);
        $data['db'][$key] = [
            'name' => $name,
            'ok' => true,
        ];
    } catch (Throwable $e) {
        $data['db'][$key] = [
            'name' => db_name($key),
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

try {
    $auth = db_conn(db_name('auth'));
    $cols = [];
    $res = $auth->query('SHOW COLUMNS FROM account');
    while ($row = $res->fetch_assoc()) {
        $cols[] = (string)$row['Field'];
    }
    $data['auth_account_columns'] = $cols;
} catch (Throwable $e) {
    $data['auth_account_columns_error'] = $e->getMessage();
}

try {
    $app = db_conn(db_name('app'));
    $data['app_tables'] = [
        'app_mobile_sessions' => table_exists($app, 'app_mobile_sessions'),
        'app_device_tokens' => table_exists($app, 'app_device_tokens'),
        'push_tokens' => table_exists($app, 'push_tokens'),
    ];
} catch (Throwable $e) {
    $data['app_tables_error'] = $e->getMessage();
}

app_json(true, 'debug', $data);
