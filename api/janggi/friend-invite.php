<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store,private');
header('X-Content-Type-Options: nosniff');
function invite_reply(int $code,array $data):never {
 http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')invite_reply(405,['ok'=>false,'error'=>'method']);
if(($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-friend-invite')
 invite_reply(403,['ok'=>false,'error'=>'header']);
$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
if($origin!==''&&$origin!=='https://stimpack.pe.kr')
 invite_reply(403,['ok'=>false,'error'=>'origin']);
$site=(string)($_SERVER['HTTP_SEC_FETCH_SITE']??'');
if($site!==''&&$site!=='same-origin')
 invite_reply(403,['ok'=>false,'error'=>'site']);
$raw=file_get_contents('php://input',false,null,0,2049);
if(!is_string($raw)||strlen($raw)>2048)invite_reply(413,['ok'=>false,'error'=>'size']);
$data=json_decode($raw,true);
if(!is_array($data))invite_reply(400,['ok'=>false,'error'=>'json']);
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
try {
 $me=janggi_authenticated_account_key();
 if(!$me)invite_reply(401,['ok'=>false,'error'=>'login_required']);
 $db=gamelab_db();
 $action=(string)($data['action']??'');
 if($action==='invite'){
  $peer=(string)($data['peer']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$peer)||hash_equals($me,$peer))
   invite_reply(400,['ok'=>false,'error'=>'peer']);
  $q=$db->prepare("SELECT COUNT(*) FROM janggi_friend_requests WHERE status='accepted'
   AND ((sender_key=? AND receiver_key=?) OR (sender_key=? AND receiver_key=?))");
  $q->execute([$me,$peer,$peer,$me]);
  if(!(int)$q->fetchColumn())invite_reply(403,['ok'=>false,'error'=>'not_friends']);
  $q=$db->prepare("SELECT COUNT(*) FROM janggi_friend_presence
   WHERE account_key=? AND last_seen >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)");
  $q->execute([$peer]);
  if(!(int)$q->fetchColumn())invite_reply(409,['ok'=>false,'error'=>'friend_offline']);
  $db->beginTransaction();
  $db->prepare("DELETE FROM janggi_friend_invitations WHERE status='pending'
   AND created_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)")->execute();
  $q=$db->prepare("SELECT id FROM janggi_friend_invitations WHERE
   sender_key=? AND receiver_key=? AND status='pending' LIMIT 1");
  $q->execute([$me,$peer]);$existing=$q->fetchColumn();
  if($existing){$db->commit();invite_reply(200,['ok'=>true,'id'=>(int)$existing,'status'=>'pending']);}
  $q=$db->prepare("INSERT INTO janggi_friend_invitations(sender_key,receiver_key) VALUES(?,?)");
  $q->execute([$me,$peer]);$id=(int)$db->lastInsertId();$db->commit();
  invite_reply(200,['ok'=>true,'id'=>$id,'status'=>'pending']);
 }
 if($action==='list'){
  $q=$db->prepare("SELECT id,sender_key,created_at FROM janggi_friend_invitations
   WHERE receiver_key=? AND status='pending' AND created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)
   ORDER BY id DESC LIMIT 30");
  $q->execute([$me]);invite_reply(200,['ok'=>true,'items'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
 }
 invite_reply(400,['ok'=>false,'error'=>'action']);
} catch(Throwable $e){
 if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();
 error_log('janggi_friend_invite_failed');
 invite_reply(503,['ok'=>false,'error'=>'server_unavailable']);
}
