<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function presence_reply(int $status,array $body): never {
 http_response_code($status);
 echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')presence_reply(405,['ok'=>false,'error'=>'method']);
if(($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-presence')
 presence_reply(403,['ok'=>false,'error'=>'header']);
$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
if($origin!==''&&$origin!=='https://stimpack.pe.kr')
 presence_reply(403,['ok'=>false,'error'=>'origin']);
$site=(string)($_SERVER['HTTP_SEC_FETCH_SITE']??'');
if($site!==''&&$site!=='same-origin')presence_reply(403,['ok'=>false,'error'=>'site']);
$raw=file_get_contents('php://input',false,null,0,1025);
if(!is_string($raw)||strlen($raw)>1024)presence_reply(413,['ok'=>false,'error'=>'size']);
$body=json_decode($raw,true);
$action=is_array($body)?($body['action']??'refresh'):'refresh';
if(!in_array($action,['online','refresh','offline'],true))
 presence_reply(400,['ok'=>false,'error'=>'action']);
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
require_once __DIR__.'/friend-ws-notify.php';
try {
 $me=janggi_authenticated_account_key();
 if(!$me)presence_reply(401,['ok'=>false,'error'=>'login_required']);
 $db=gamelab_db();
 // Presence is event-driven: login, explicit refresh, and logout/exit only.
 if($action==='offline'){
  $q=$db->prepare("DELETE FROM janggi_friend_presence WHERE account_key=?");
  $q->execute([$me]);
  presence_reply(200,['ok'=>true]);
 }
 if($action==='online'){
  $q=$db->prepare("INSERT INTO janggi_friend_presence(account_key,online_since,last_seen)
   VALUES(?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
   ON DUPLICATE KEY UPDATE last_seen=UTC_TIMESTAMP()");
  $q->execute([$me]);
 }
 $q=$db->prepare("SELECT p.account_key AS peer, UNIX_TIMESTAMP(p.online_since) AS online_since,
  CASE WHEN f.sender_key=? THEN f.receiver_name ELSE f.sender_name END AS name
 FROM janggi_friend_requests f
 JOIN janggi_friend_presence p ON p.account_key =
  CASE WHEN f.sender_key=? THEN f.receiver_key ELSE f.sender_key END
 WHERE (f.sender_key=? OR f.receiver_key=?) AND f.status='accepted'
 AND p.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
 ORDER BY p.last_seen DESC LIMIT 100");
 $q->execute([$me,$me,$me,$me]);
 $rows=$q->fetchAll(PDO::FETCH_ASSOC);
 // Live WebSocket connections determine online status; 24-hour records are not proof.
 $online=janggi_friend_ws_online_accounts(array_column($rows,'peer'));
 if($online===null)presence_reply(503,['ok'=>false,'error'=>'live_presence_unavailable']);
 $active=array_values(array_filter($rows,static fn($row)=>in_array($row['peer'],$online,true)));
 presence_reply(200,['ok'=>true,'friends'=>$active]);
} catch(Throwable $e) {
 if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();
 error_log('janggi_presence_failed');
 presence_reply(503,['ok'=>false,'error'=>'server_unavailable']);
}
