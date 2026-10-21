<?php
declare(strict_types=1);

/**
 * Stable, server-only HMAC secret for pseudonymous Janggi account IDs.
 *
 * Preferred: STIMPACK_JANGGI_ACCOUNT_HMAC_SECRET in PHP-FPM environment.
 * Alternative: /etc/stimpack/janggi_account_hmac.key (never in web root).
 *
 * Both the account lookup and record ingest must use the same key.
 */
function janggi_account_secret(): string
{
    $env = getenv('STIMPACK_JANGGI_ACCOUNT_HMAC_SECRET');
    if (is_string($env) && strlen(trim($env)) >= 32) {
        return trim($env);
    }

    $file = '/etc/stimpack/janggi_account_hmac.key';
    $raw = @file_get_contents($file);
    if (is_string($raw)) {
        $secret = trim($raw);
        // The provision command generates a random 32-byte key as 64 hex chars.
        if (preg_match('/^[a-f0-9]{64}$/iD', $secret) === 1) {
            return $secret;
        }
    }

    return '';
}

function janggi_pseudonymous_account_id(string $googleSub): ?string
{
    $secret = janggi_account_secret();
    $googleSub = trim($googleSub);
    if ($secret === '' || $googleSub === '') {
        return null;
    }
    return hash_hmac('sha256', $googleSub, $secret);
}
