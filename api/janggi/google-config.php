<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/janggi_google_auth.php';
janggi_auth_headers();
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
    header('Allow: GET');janggi_auth_json(405,['ok'=>false,'message'=>'GET만 허용됩니다.']);
}
if(($_SERVER['HTTP_HOST']??'')!=='stimpack.pe.kr')
    janggi_auth_json(403,['ok'=>false,'message'=>'장기 공식 사이트에서만 이용할 수 있습니다.']);
$client=janggi_google_client_id();
if($client==='')janggi_auth_json(503,['ok'=>false,'message'=>'장기 Google 클라이언트 ID 설정이 필요합니다.']);
janggi_google_session();
$_SESSION['janggi_google_nonce']=bin2hex(random_bytes(24));
janggi_auth_json(200,['ok'=>true,'clientId'=>$client,'nonce'=>$_SESSION['janggi_google_nonce']]);
