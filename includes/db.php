<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';


if (!function_exists('gamelab_db')) {

    function gamelab_db(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $config = gamelab_config();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['DB_HOST'],
            $config['DB_PORT'],
            $config['DB_NAME'],
            $config['CHARSET']
        );

        $pdo = new PDO(
            $dsn,
            (string)$config['DB_USER'],
            (string)$config['DB_PASS'],
            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE =>
                    PDO::FETCH_ASSOC,

                PDO::ATTR_EMULATE_PREPARES =>
                    false,
            ]
        );

        return $pdo;
    }
}
