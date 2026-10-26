<?php
declare(strict_types=1);
// Deploy to /api/janggi/friends.php. All permissions derive from verified Janggi login.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(int $status,array $body):never {http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')respond(405,['ok'=>false,'error'=>'method']);
if((int)($_SERVER['CONTENT_LENGTH']??0)>2048)respond(413,['ok'=>false,'error'=>'size']);
if(($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-friends')respond(403,['ok'=>false,'error'=>'request_header']);
$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
if($origin!==''&&$origin!=='https://stimpack.pe.kr')respond(403,['ok'=>false,'error'=>'origin']);
$site=(string)($_SERVER['HTTP_SEC_FETCH_SITE']??'');
if($site!==''&&$site!=='same-origin')respond(403,['ok'=>false,'error'=>'site']);
$data=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($data))respond(400,['ok'=>false,'error'=>'json']);
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
try {$me=janggi_authenticated_account_key();$db=gamelab_db();}
catch(Throwable $e){respond(503,['ok'=>false,'error'=>'auth_unavailable']);}
if(!$me)respond(401,['ok'=>false,'error'=>'login_required']);
$action=(string)($data['action']??'');
try {
 if($action==='list'){
  $q=$db->prepare("SELECT id,sender_key,receiver_key,sender_name,receiver_name,status FROM janggi_friend_requests
   WHERE (sender_key=? OR receiver_key=?) AND status IN ('pending','accepted') ORDER BY updated_at DESC LIMIT 300");
  $q->execute([$me,$me]);
  $items=[];
  foreach($q as $row){
   $outgoing=hash_equals($me,$row['sender_key']);
   $items[]=['id'=>(int)$row['id'],'peer'=>$outgoing?$row['receiver_key']:$row['sender_key'],
    'name'=>$outgoing?$row['receiver_name']:$row['sender_name'],
    'status'=>$row['status'],'outgoing'=>$outgoing];
  }
  respond(200,['ok'=>true,'items'=>$items]);
 }
 if($action==='request'){
  $room=(int)($data['room']??0);
  $token=(string)($data['token']??'');
  if($room<1||!preg_match('/^[a-f0-9]{48}$/D',$token))respond(400,['ok'=>false,'error'=>'room']);
  // Both parties must have a verified account linked to the live room.
  $q=$db->prepare("SELECT side,account_key,display_name FROM janggi_pvp_players WHERE room_id=? AND token_hash=?");
  $q->execute([$room,hash('sha256',$token)]);$participant=$q->fetch(PDO::FETCH_ASSOC);
  if(!$participant||!hash_equals($me,(string)$participant['account_key']))respond(403,['ok'=>false,'error'=>'not_room_participant']);
  $q=$db->prepare("SELECT account_key,display_name FROM janggi_pvp_players WHERE room_id=? AND side<>? LIMIT 1");
  $q->execute([$room,$participant['side']]);$opponent=$q->fetch(PDO::FETCH_ASSOC);
  $peer=$opponent['account_key']??null;
  if(!is_string($peer)||!preg_match('/^[a-f0-9]{64}$/D',$peer)||hash_equals($me,$peer))
   respond(409,['ok'=>false,'error'=>'opponent_not_linked']);
  $check=$db->prepare("SELECT id,sender_key,status FROM janggi_friend_requests
    WHERE (sender_key=? AND receiver_key=?) OR (sender_key=? AND receiver_key=?) LIMIT 1");
  $check->execute([$me,$peer,$peer,$me]);$existing=$check->fetch(PDO::FETCH_ASSOC);
  if($existing){
   if($existing['status']==='accepted')respond(200,['ok'=>true,'status'=>'accepted']);
   if($existing['status']==='pending')respond(200,['ok'=>true,'status'=>'pending']);
   $upd=$db->prepare("UPDATE janggi_friend_requests SET sender_key=?,receiver_key=?,sender_name=?,receiver_name=?,status='pending' WHERE id=?");
   $upd->execute([$me,$peer,$participant['display_name'],$opponent['display_name'],$existing['id']]);
  }else{
   $ins=$db->prepare("INSERT INTO janggi_friend_requests(sender_key,receiver_key,sender_name,receiver_name) VALUES(?,?,?,?)");
   $ins->execute([$me,$peer,$participant['display_name'],$opponent['display_name']]);
  }
  respond(200,['ok'=>true,'status'=>'pending']);
 }
 if(in_array($action,['approve','decline','remove'],true)){
  $id=(int)($data['id']??0);if($id<1)respond(400,['ok'=>false,'error'=>'id']);
  $db->beginTransaction();
  $q=$db->prepare('SELECT sender_key,receiver_key,status FROM janggi_friend_requests WHERE id=? FOR UPDATE');
  $q->execute([$id]);$request=$q->fetch(PDO::FETCH_ASSOC);
  if(!$request){$db->rollBack();respond(404,['ok'=>false,'error'=>'not_found']);}
  if($action==='remove'){
   if($request['sender_key']!==$me&&$request['receiver_key']!==$me){$db->rollBack();respond(403,['ok'=>false,'error'=>'not_allowed']);}
   $status='removed';
  }else{
   if(!hash_equals((string)$request['receiver_key'],$me)||$request['status']!=='pending'){
    $db->rollBack();respond(403,['ok'=>false,'error'=>'not_receiver']);}
   $status=$action==='approve'?'accepted':'declined';
  }
  $db->prepare('UPDATE janggi_friend_requests SET status=? WHERE id=?')->execute([$status,$id]);
  $db->commit();respond(200,['ok'=>true,'status'=>$status]);
 }
 respond(400,['ok'=>false,'error'=>'action']);
}catch(Throwable $e){
 if($db->inTransaction())$db->rollBack();
 error_log('janggi friends operation failed');
 respond(503,['ok'=>false,'error'=>'server_unavailable']);
}
