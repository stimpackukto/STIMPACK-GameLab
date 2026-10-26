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
require_once __DIR__.'/friend-ws-notify.php';
try {
 $me=janggi_authenticated_account_key();
 if(!$me)invite_reply(401,['ok'=>false,'error'=>'login_required']);
 $db=gamelab_db();
 $action=(string)($data['action']??'');
 // Expire pending invites without requiring a background worker.
 $db->prepare("UPDATE janggi_friend_invitations SET status='cancelled'
  WHERE status='pending' AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)")->execute();
 if($action==='invite'){
  $peer=(string)($data['peer']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$peer)||hash_equals($me,$peer))
   invite_reply(400,['ok'=>false,'error'=>'peer']);
  $q=$db->prepare("SELECT COUNT(*) FROM janggi_friend_requests WHERE status='accepted'
   AND ((sender_key=? AND receiver_key=?) OR (sender_key=? AND receiver_key=?))");
  $q->execute([$me,$peer,$peer,$me]);
  if(!(int)$q->fetchColumn())invite_reply(403,['ok'=>false,'error'=>'not_friends']);
  $q=$db->prepare("SELECT COUNT(*) FROM janggi_friend_presence WHERE account_key=?
   AND last_seen>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)");
  $q->execute([$peer]);
  if(!(int)$q->fetchColumn())invite_reply(409,['ok'=>false,'error'=>'friend_offline']);
  $q=$db->prepare("SELECT id FROM janggi_friend_invitations WHERE sender_key=?
   AND receiver_key=? AND status IN ('pending','accepted') ORDER BY id DESC LIMIT 1");
  $q->execute([$me,$peer]);$existing=$q->fetchColumn();
  if($existing)invite_reply(200,['ok'=>true,'id'=>(int)$existing,'status'=>'pending']);
  $q=$db->prepare("INSERT INTO janggi_friend_invitations(sender_key,receiver_key) VALUES(?,?)");
  $q->execute([$me,$peer]);
  $newId=(int)$db->lastInsertId();
  janggi_friend_ws_notify($peer,$newId,'invite');
  invite_reply(200,['ok'=>true,'id'=>$newId,'status'=>'pending']);
 }
 if($action==='list'){
  $q=$db->prepare("SELECT i.id,i.sender_key,i.receiver_key,i.status,i.room_id,i.created_at,
   CASE WHEN f.sender_key=(CASE WHEN i.sender_key=? THEN i.receiver_key ELSE i.sender_key END) THEN f.sender_name ELSE f.receiver_name END AS name
   FROM janggi_friend_invitations i
   JOIN janggi_friend_requests f ON f.status='accepted'
    AND ((f.sender_key=i.sender_key AND f.receiver_key=i.receiver_key)
      OR (f.sender_key=i.receiver_key AND f.receiver_key=i.sender_key))
   WHERE (i.sender_key=? OR i.receiver_key=?) AND i.status IN ('pending','accepted')
   ORDER BY i.id DESC LIMIT 40");
  $q->execute([$me,$me,$me]);
  $items=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
   $outgoing=hash_equals($me,$row['sender_key']);
   $items[]=['id'=>(int)$row['id'],'name'=>$row['name'],'outgoing'=>$outgoing,
    'status'=>$row['status'],'peer'=>$outgoing?$row['receiver_key']:$row['sender_key'],
    'roomReady'=>$row['room_id']!==null];
  }
  invite_reply(200,['ok'=>true,'items'=>$items]);
 }
 if(in_array($action,['accept','decline','cancel','session'],true)){
  $id=(int)($data['id']??0);
  if($id<1)invite_reply(400,['ok'=>false,'error'=>'id']);
  $db->beginTransaction();
  $q=$db->prepare("SELECT * FROM janggi_friend_invitations WHERE id=? FOR UPDATE");
  $q->execute([$id]);$inv=$q->fetch(PDO::FETCH_ASSOC);
  if(!$inv||($me!==$inv['sender_key']&&$me!==$inv['receiver_key'])){
   $db->rollBack();invite_reply(403,['ok'=>false,'error'=>'not_allowed']);
  }
  $receiver=hash_equals($me,$inv['receiver_key']);
  if($action==='decline'||$action==='cancel'){
   if(($action==='decline'&&!$receiver)||($action==='cancel'&&$receiver)||
     $inv['status']!=='pending'){
    $db->rollBack();invite_reply(409,['ok'=>false,'error'=>'invalid_state']);
   }
   $db->prepare("UPDATE janggi_friend_invitations SET status=? WHERE id=?")
    ->execute([$action==='decline'?'declined':'cancelled',$id]);
   $db->commit();
   janggi_friend_ws_notify($receiver?$inv['sender_key']:$inv['receiver_key'],$id,$action);
   invite_reply(200,['ok'=>true,'status'=>$action]);
  }
  if($action==='accept'&&$inv['status']==='pending'&&$receiver){
   // A direct invitation creates a private room, not a random-match queue entry.
   $senderToken=bin2hex(random_bytes(24));$receiverToken=bin2hex(random_bytes(24));
   $db->prepare("INSERT INTO janggi_pvp_rooms
    (status,turn,seq,created_at,updated_at,deadline_at,match_random,match_rank,rank_stage)
    VALUES('ready','blue',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),
     DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),0,0,0)")->execute();
   $room=(int)$db->lastInsertId();
   $senderName='친구';$receiverName='친구';
   $names=$db->prepare("SELECT sender_key,receiver_key,sender_name,receiver_name
    FROM janggi_friend_requests WHERE status='accepted'
    AND ((sender_key=? AND receiver_key=?) OR (sender_key=? AND receiver_key=?)) LIMIT 1");
   $names->execute([$inv['sender_key'],$inv['receiver_key'],$inv['receiver_key'],$inv['sender_key']]);
   $friend=$names->fetch(PDO::FETCH_ASSOC);
   if($friend){
    $senderName=$friend['sender_key']===$inv['sender_key']?$friend['sender_name']:$friend['receiver_name'];
    $receiverName=$friend['sender_key']===$inv['receiver_key']?$friend['sender_name']:$friend['receiver_name'];
   }
   $ins=$db->prepare("INSERT INTO janggi_pvp_players
    (room_id,token_hash,side,display_name,ai_rank,fairy_rate,account_key,accepted)
    VALUES(?,? ,?,?,'입문',NULL,?,1)");
   $ins->execute([$room,hash('sha256',$senderToken),'blue',$senderName,$inv['sender_key']]);
   $ins->execute([$room,hash('sha256',$receiverToken),'red',$receiverName,$inv['receiver_key']]);
   $db->prepare("UPDATE janggi_friend_invitations SET status='accepted',
    room_id=?,sender_token=?,receiver_token=? WHERE id=?")
    ->execute([$room,$senderToken,$receiverToken,$id]);
   $inv['status']='accepted';$inv['room_id']=$room;
   $inv['sender_token']=$senderToken;$inv['receiver_token']=$receiverToken;
  }
  if($inv['status']!=='accepted'||!$inv['room_id']){
   $db->rollBack();invite_reply(409,['ok'=>false,'error'=>'not_accepted']);
  }
  $room=(int)$inv['room_id'];
  $roomQuery=$db->prepare("SELECT status FROM janggi_pvp_rooms WHERE id=?");
  $roomQuery->execute([$room]);
  $roomStatus=$roomQuery->fetchColumn();
  if(!in_array($roomStatus,['ready','playing'],true)){
   $db->rollBack();invite_reply(409,['ok'=>false,'error'=>'room_closed']);
  }
  $token=$receiver?$inv['receiver_token']:$inv['sender_token'];
  $side=$receiver?'red':'blue';
  $db->commit();
  if($action==='accept')janggi_friend_ws_notify($inv['sender_key'],$id,'accept');
  invite_reply(200,['ok'=>true,'status'=>'accepted','session'=>[
   'room'=>$room,'token'=>$token,'side'=>$side]]);
 }
 invite_reply(400,['ok'=>false,'error'=>'action']);
} catch(Throwable $e){
 if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();
 error_log('janggi_friend_invite_failed');
 invite_reply(503,['ok'=>false,'error'=>'server_unavailable']);
}
