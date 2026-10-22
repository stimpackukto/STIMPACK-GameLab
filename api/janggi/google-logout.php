<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/janggi_google_auth.php';
janggi_auth_headers();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');janggi_auth_json(405,['ok'=>false,'message'=>'POST만 허용됩니다.']);
}
if(!janggi_google_origin_required()||
    ($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-google'){
    janggi_auth_json(403,['ok'=>false,'message'=>'요청 출처가 올바르지 않습니다.']);
}
janggi_google_session();
unset($_SESSION['janggi_identity'],$_SESSION['janggi_google_nonce']);
session_regenerate_id(true);
session_write_close();
janggi_auth_json(200,['ok'=>true]);
