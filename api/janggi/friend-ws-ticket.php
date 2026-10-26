<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-friend-ws'){http_response_code(403);exit('{"ok":false}');}
$origin=$_SERVER['HTTP_ORIGIN']??'';
if($origin!==''&&$origin!=='https://stimpack.pe.kr'){http_response_code(403);exit('{"ok":false}');}
$site=$_SERVER['HTTP_SEC_FETCH_SITE']??'';
if($site!==''&&$site!=='same-origin'){http_response_code(403);exit('{"ok":false}');}
$secret=getenv('JANGGI_FRIEND_WS_SECRET')?:'';
if(strlen($secret)<32){http_response_code(503);exit('{"ok":false,"error":"ws_not_configured"}');}
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
try{$account=janggi_authenticated_account_key();}catch(Throwable $e){$account=null;}
if(!$account){http_response_code(401);exit('{"ok":false,"error":"login_required"}');}
$exp=time()+90;
$nonce=bin2hex(random_bytes(12));
$payload="$account.$exp.$nonce";
$signature=hash_hmac('sha256',$payload,$secret);
echo json_encode(['ok'=>true,'ticket'=>$payload.'.'.$signature,'expiresIn'=>90],JSON_UNESCAPED_SLASHES);
