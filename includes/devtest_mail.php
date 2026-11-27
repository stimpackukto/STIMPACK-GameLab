<?php
declare(strict_types=1);

/**
 * STIMPACK GAME LAB devtest email transport.
 * Reuses the WoW web site's PHPMailer + Gmail SMTP configuration.
 * Secrets live exclusively in /etc/trinity-web.env; never commit them.
 */
function gamelab_devtest_send_smtp_mail(string $to, string $subject, string $body): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
        throw new RuntimeException('메일 수신 주소가 올바르지 않습니다.');
    }

    // Same setting file and key names used by the WoW web mailer.
    $envFile = '/etc/trinity-web.env';
    if (!is_readable($envFile)) {
        error_log('[GAME LAB DEVTEST SMTP] config_unreadable: ' . $envFile);
        throw new RuntimeException('SMTP 설정 파일을 읽을 수 없습니다. 서버 관리자에게 문의해 주세요.');
    }
    $env = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if (!is_array($env)) {
        error_log('[GAME LAB DEVTEST SMTP] config_parse_failed');
        throw new RuntimeException('SMTP 설정을 읽는 중 오류가 발생했습니다.');
    }

    $host = trim((string)($env['GMAIL_SMTP_HOST'] ?? 'smtp.gmail.com'));
    $username = trim((string)($env['GMAIL_SMTP_USER'] ?? ''));
    $password = (string)($env['GMAIL_SMTP_PASS'] ?? '');
    $from = trim((string)($env['GMAIL_SMTP_FROM'] ?? $username));
    $fromName = trim((string)($env['GMAIL_SMTP_FROM_NAME'] ?? 'StimPack'));
    $port = (int)($env['GMAIL_SMTP_PORT'] ?? 587);
    $secure = strtolower(trim((string)($env['GMAIL_SMTP_SECURE'] ?? 'tls')));

    if ($host === '' || $username === '' || $password === '' || $from === ''
        || !filter_var($from, FILTER_VALIDATE_EMAIL) || $port < 1 || $port > 65535
        || !in_array($secure, ['', 'tls', 'ssl'], true)) {
        error_log('[GAME LAB DEVTEST SMTP] config_invalid');
        throw new RuntimeException('SMTP 발송 설정이 올바르지 않습니다. 서버 관리자에게 문의해 주세요.');
    }

    // The WoW deployment may keep PHPMailer under its original web root,
    // while GameLab uses a separate root. Reuse existing installed copies.
    $root = dirname(__DIR__);
    $paths = [
        $root . '/includes/PHPMailer/src',
        $root . '/wow/includes/PHPMailer/src',
        '/var/www/stimpack/includes/PHPMailer/src',
    ];
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $found = false;
        foreach ($paths as $base) {
            if (!is_file($base . '/Exception.php')
                || !is_file($base . '/PHPMailer.php')
                || !is_file($base . '/SMTP.php')) {
                continue;
            }
            require_once $base . '/Exception.php';
            require_once $base . '/PHPMailer.php';
            require_once $base . '/SMTP.php';
            $found = true;
            break;
        }
        if (!$found) {
            error_log('[GAME LAB DEVTEST SMTP] phpmailer_not_found');
            throw new RuntimeException('서버에서 PHPMailer를 찾을 수 없습니다. 설치 경로를 확인해 주세요.');
        }
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = $port;
        if ($secure !== '') {
            $mail->SMTPSecure = $secure;
        }
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    } catch (Throwable $error) {
        // No SMTP secrets, application email addresses, or message bodies in logs.
        error_log('[GAME LAB DEVTEST SMTP] send_failed: ' . get_class($error));
        throw new RuntimeException('SMTP 메일 전송에 실패했습니다. 서버 SMTP 설정 및 발송 로그를 확인해 주세요.', 0, $error);
    }
}
