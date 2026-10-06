<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

$config = require '/etc/stimpack/google_oauth.php';

$state = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id'     => $config['client_id'],
    'redirect_uri'  => $config['redirect_uri'],
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
];

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' .
       http_build_query($params, '', '&', PHP_QUERY_RFC3986);

header('Location: ' . $url);
exit;
