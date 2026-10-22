<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/janggi_google_auth.php';
require_once dirname(__DIR__,2).'/includes/janggi_account_key.php';
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
janggi_auth_headers();
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');janggi_auth_json(405,['ok'=>false,'message'=>'POST만 허용됩니다.']);
}
if(!janggi_google_origin_required()||
    ($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-google'||
    !str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))
    janggi_auth_json(403,['ok'=>false,'message'=>'요청 출처가 올바르지 않습니다.']);
if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)
    janggi_auth_json(413,['ok'=>false,'message'=>'인증 데이터 크기가 너무 큽니다.']);
$raw=file_get_contents('php://input',false,null,0,12001);
if(!is_string($raw)||strlen($raw)>12000)janggi_auth_json(413,['ok'=>false,'message'=>'인증 데이터 크기 오류']);
$data=json_decode($raw,true,8);
if(!is_array($data)||($data['consent']??false)!==true||
    ($data['consentVersion']??'')!=='janggi-ai-training-v1'||
    !is_string($data['credential']??null))
    janggi_auth_json(403,['ok'=>false,'message'=>'기보 전송 동의 및 Google 인증이 필요합니다.']);
$audience=janggi_google_client_id();
if($audience==='')janggi_auth_json(503,['ok'=>false,'message'=>'Google 클라이언트 설정이 필요합니다.']);
janggi_google_session();
$nonce=$_SESSION['janggi_google_nonce']??null;
if(!is_string($nonce)||!preg_match('/^[a-f0-9]{48}$/D',$nonce))
    janggi_auth_json(403,['ok'=>false,'message'=>'Google 로그인 요청을 다시 시작해 주세요.']);
// Prevent replay: the nonce is one-use, including failed token attempts.
unset($_SESSION['janggi_google_nonce']);
$loginClient=$_SESSION['janggi_login_client']??'web';
unset($_SESSION['janggi_login_client']);
try{
    $user=janggi_verify_google_token($data['credential'],$audience,$nonce);
}catch(Throwable $e){
    error_log('janggi_google_login: certificate verification service unavailable');
    janggi_auth_json(503,['ok'=>false,'message'=>'Google 인증 서비스를 확인할 수 없습니다.']);
}
if($user===null)janggi_auth_json(401,['ok'=>false,'message'=>'Google 인증 결과가 올바르지 않습니다.']);
$id=janggi_pseudonymous_account_id($user['sub']);
if($id===null)janggi_auth_json(503,['ok'=>false,'message'=>'장기 서버 비밀키 설정이 필요합니다.']);
session_regenerate_id(true);
// Re-authenticating with Google replaces any previous remembered browser login.
// The old token is revoked rather than left active until its expiry date.
try{
    if(janggi_device_cookie_value()!==null)janggi_revoke_device_login();
}catch(Throwable $e){
    error_log('janggi_device_login: previous device session revocation failed');
    session_write_close();
    janggi_auth_json(503,['ok'=>false,'message'=>'기존 로그인 세션을 정리하지 못했습니다.']);
}
if($loginClient==='android'){
    try {
        // A durable browser cookie is stored by the Android TWA browser.
        // The DB stores only its SHA-256 hash and an HMAC account identifier.
        janggi_issue_device_login($id);
    }catch(Throwable $e){
        error_log('janggi_device_login: cannot persist secure device session');
        session_write_close();
        janggi_auth_json(503,['ok'=>false,'message'=>'기기 로그인 저장을 설정하지 못했습니다.']);
    }
    unset($_SESSION['janggi_identity'],$_SESSION['janggi_last_active']);
}else{
    // PC session is valid only while active; independent of GAME LAB login.
    $_SESSION['janggi_identity']=['sub'=>$user['sub'],'consentVersion'=>'janggi-ai-training-v1'];
    $_SESSION['janggi_last_active']=time();
}
session_write_close();
janggi_auth_json(200,['ok'=>true,'authenticated'=>true,'user'=>[
    'id'=>$id,'name'=>$user['name'],'email'=>$user['email'],'picture'=>$user['picture']
]]);
