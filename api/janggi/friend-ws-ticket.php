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
// Include only accepted friends in the signed ticket; Node verifies the roster.
require_once dirname(__DIR__,2).'/includes/db.php';
try {
 $db=gamelab_db();
 $q=$db->prepare("SELECT CASE WHEN sender_key=? THEN receiver_key ELSE sender_key END AS peer
  FROM janggi_friend_requests WHERE status='accepted' AND (sender_key=? OR receiver_key=?)
  LIMIT 100");
 $q->execute([$account,$account,$account]);
 $peers=array_values(array_unique(array_filter($q->fetchAll(PDO::FETCH_COLUMN),static fn($v)=>
  is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v))));
}catch(Throwable $e){http_response_code(503);exit('{"ok":false,"error":"friends_unavailable"}');}
$roster=rtrim(strtr(base64_encode(json_encode($peers)),'+/','-_'),'=');
$exp=time()+90;
$nonce=bin2hex(random_bytes(12));
$payload="$account.$exp.$nonce.$roster";
$signature=hash_hmac('sha256',$payload,$secret);
echo json_encode(['ok'=>true,'ticket'=>$payload.'.'.$signature,'expiresIn'=>90],JSON_UNESCAPED_SLASHES);
