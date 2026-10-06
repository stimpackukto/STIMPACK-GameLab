<?php
// /api/community/lib/response.php

// 앱 API는 오류가 나도 빈 500을 보내지 않고 JSON으로 응답한다.
// Android 쪽에서 원인을 바로 볼 수 있게 하기 위함이다.

declare(strict_types=1);

if (!function_exists('app_json')) {
    function app_json(bool $success, string $message = '', array $data = [], int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('app_input')) {
    function app_input(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
        }
        return array_merge($_GET, $_POST);
    }
}

set_exception_handler(function (Throwable $e): void {
    app_json(false, '서버 오류: ' . $e->getMessage(), [], 200);
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode([
        'success' => false,
        'message' => '서버 치명 오류: ' . (string)$err['message'],
        'data' => [
            'file' => basename((string)$err['file']),
            'line' => (int)$err['line'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});
