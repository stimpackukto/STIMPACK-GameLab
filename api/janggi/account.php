<?php
declare(strict_types=1);
// Independent Janggi Google session. GAME LAB account login is not consulted.
require_once dirname(__DIR__,2).'/includes/janggi_google_auth.php';
require_once dirname(__DIR__,2).'/includes/janggi_account_key.php';
janggi_auth_headers();
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
    header('Allow: GET');janggi_auth_json(405,['ok'=>false,'authenticated'=>false,'message'=>'GET만 허용됩니다.']);
}
janggi_google_session();
$user=$_SESSION['janggi_identity']??null;
if(!is_array($user)||!is_string($user['sub']??null)||
    ($user['consentVersion']??'')!=='janggi-ai-training-v1'){
    janggi_auth_json(200,['ok'=>true,'authenticated'=>false,'user'=>null]);
}
$id=janggi_pseudonymous_account_id($user['sub']);
if($id===null)janggi_auth_json(503,['ok'=>false,'authenticated'=>false,
    'message'=>'장기 서버 비밀키 설정이 필요합니다.']);
// Name and e-mail exist only in local browser storage, not on the server.
janggi_auth_json(200,['ok'=>true,'authenticated'=>true,'user'=>['id'=>$id]]);
