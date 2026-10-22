<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/janggi_google_auth.php';
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
janggi_auth_headers();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');janggi_auth_json(405,['ok'=>false,'message'=>'POST만 허용됩니다.']);
}
if(!janggi_google_origin_required()||
    ($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-google'){
    janggi_auth_json(403,['ok'=>false,'message'=>'요청 출처가 올바르지 않습니다.']);
}
try{
    janggi_revoke_device_login();
}catch(Throwable $e){
    error_log('janggi_device_logout: revoke failed');
    janggi_auth_json(503,['ok'=>false,'message'=>'기기 로그아웃에 실패했습니다. 다시 시도해 주세요.']);
}
janggi_google_session();
unset($_SESSION['janggi_identity'],$_SESSION['janggi_google_nonce'],$_SESSION['janggi_last_active'],$_SESSION['janggi_login_client']);
session_regenerate_id(true);
session_write_close();
janggi_auth_json(200,['ok'=>true]);
