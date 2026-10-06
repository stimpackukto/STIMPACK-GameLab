<?php
declare(strict_types=1);

$fcmSoapConfigCandidates = [
    __DIR__ . '/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../../config.php',
    __DIR__ . '/../../includes/config.php',
    __DIR__ . '/../../../config.php',
    __DIR__ . '/../../../includes/config.php',
    __DIR__ . '/../../../../config.php',
    __DIR__ . '/../../../../includes/config.php',
];

foreach ($fcmSoapConfigCandidates as $fcmSoapConfigPath) {
    if (is_file($fcmSoapConfigPath)) {
        require_once $fcmSoapConfigPath;
        break;
    }
}

if (!function_exists('fcm_soap_settings')) {
    function fcm_soap_settings(): array
    {
        global $soap_config;

        $host = defined('SOAP_HOST') ? (string)SOAP_HOST : '';
        $port = defined('SOAP_PORT') ? (int)SOAP_PORT : 7878;
        $username = defined('SOAP_USER') ? (string)SOAP_USER : (defined('SOAP_USERNAME') ? (string)SOAP_USERNAME : '');
        $password = defined('SOAP_PASS') ? (string)SOAP_PASS : (defined('SOAP_PASSWORD') ? (string)SOAP_PASSWORD : '');

        if ($host === '' && isset($soap_config['host'])) {
            $host = (string)$soap_config['host'];
        }

        if ($username === '' && isset($soap_config['username'])) {
            $username = (string)$soap_config['username'];
        }

        if ($password === '' && isset($soap_config['password'])) {
            $password = (string)$soap_config['password'];
        }

        if ($host === '' && function_exists('server_env')) {
            $host = (string)server_env('SOAP_HOST', '127.0.0.1');
        }

        if ($username === '' && function_exists('server_env')) {
            $username = (string)server_env('SOAP_USER', (string)server_env('SOAP_USERNAME', ''));
        }

        if ($password === '' && function_exists('server_env')) {
            $password = (string)server_env('SOAP_PASS', (string)server_env('SOAP_PASSWORD', ''));
        }

        if ($host === '') {
            $host = '127.0.0.1';
        }

        if ($port <= 0) {
            $port = 7878;
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ];
    }
}

if (!function_exists('fcm_execute_soap_command')) {
    function fcm_execute_soap_command($command, $port = 7878): array
    {
        $command = (string)$command;
        $settings = fcm_soap_settings();
        $host = (string)$settings['host'];
        $port = (int)$port > 0 ? (int)$port : (int)$settings['port'];
        $username = (string)$settings['username'];
        $password = (string)$settings['password'];

        if ($command === '') {
            return ['sent' => false, 'message' => 'SOAP 명령어가 비어 있습니다.'];
        }

        if ($username === '' || $password === '') {
            return ['sent' => false, 'message' => 'SOAP 계정 정보가 설정되어 있지 않습니다.'];
        }

        if (class_exists('SoapClient')) {
            try {
                $connection = new SoapClient(null, [
                    'location' => 'http://' . $host . ':' . $port,
                    'uri' => 'urn:TC',
                    'login' => $username,
                    'password' => $password,
                    'keep_alive' => false,
                ]);

                $connection->executeCommand(new SoapParam($command, 'command'));
                unset($connection);

                return ['sent' => true, 'message' => '성공적으로 전송됨'];
            } catch (Throwable $e) {
                if (isset($connection)) {
                    unset($connection);
                }

                return ['sent' => false, 'message' => $e->getMessage()];
            }
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:ns1="urn:TC">'
            . '<SOAP-ENV:Body>'
            . '<ns1:executeCommand>'
            . '<command>' . htmlspecialchars($command, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</command>'
            . '</ns1:executeCommand>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "urn:TC#executeCommand"',
            'Authorization: Basic ' . base64_encode($username . ':' . $password),
            'Content-Length: ' . strlen($body),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('http://' . $host . ':' . $port . '/', false, $context);

        if ($response === false) {
            return ['sent' => false, 'message' => 'SOAP 서버에 연결할 수 없습니다.'];
        }

        if (
            stripos($response, 'faultstring') !== false ||
            stripos($response, 'Command failed') !== false ||
            stripos($response, 'Authentication failed') !== false
        ) {
            return ['sent' => false, 'message' => 'SOAP 명령 실행 실패', 'raw' => $response];
        }

        return ['sent' => true, 'message' => '성공적으로 전송됨', 'raw' => $response];
    }
}

if (!function_exists('ExecuteSoapCommand')) {
    function ExecuteSoapCommand($command, $port = 7878)
    {
        return fcm_execute_soap_command($command, $port);
    }
}