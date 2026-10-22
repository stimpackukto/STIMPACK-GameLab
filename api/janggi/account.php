<?php
declare(strict_types=1);
// Independent Janggi Google session. GAME LAB account login is not consulted.
require_once dirname(__DIR__,2).'/includes/janggi_google_auth.php';
require_once dirname(__DIR__,2).'/includes/janggi_account_key.php';
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
janggi_auth_headers();
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
    header('Allow: GET');janggi_auth_json(405,['ok'=>false,'authenticated'=>false,'message'=>'GET만 허용됩니다.']);
}
try{
    $id=janggi_authenticated_account_key();
}catch(Throwable $e){
    error_log('janggi_account_session: lookup unavailable');
    janggi_auth_json(503,['ok'=>false,'authenticated'=>false,
      'message'=>'로그인 서버를 확인할 수 없습니다.']);
}
if($id===null)janggi_auth_json(200,['ok'=>true,'authenticated'=>false,'user'=>null]);
janggi_auth_json(200,['ok'=>true,'authenticated'=>true,'user'=>['id'=>$id]]);
