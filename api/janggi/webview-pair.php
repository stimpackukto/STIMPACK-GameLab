<?php
declare(strict_types=1);
// Browser Google sign-in -> WebView handoff. Never expose browser session cookies.
require_once dirname(__DIR__,2).'/includes/janggi_google_auth.php';
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
janggi_auth_headers();
if(($_SERVER['HTTP_HOST']??'')!=='stimpack.pe.kr')janggi_auth_json(403,['ok'=>false]);
$method=$_SERVER['REQUEST_METHOD']??'';
if($method!=='POST'){header('Allow: POST');janggi_auth_json(405,['ok'=>false]);}
if(!janggi_google_origin_required()||($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-webview')
 janggi_auth_json(403,['ok'=>false,'message'=>'Invalid request origin']);
if((int)($_SERVER['CONTENT_LENGTH']??0)>1024)janggi_auth_json(413,['ok'=>false]);
$data=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($data))janggi_auth_json(400,['ok'=>false]);
$action=$data['action']??'';
try {
 $db=janggi_device_db();
 if($action==='create'){
  $id=bin2hex(random_bytes(24));$secret=bin2hex(random_bytes(32));
  $q=$db->prepare("INSERT INTO gamelab_janggi_webview_pairs(pair_hash,secret_hash,state,expires_at)
   VALUES(?,?,'pending',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))");
  $q->execute([hash('sha256',$id),hash('sha256',$secret)]);
  janggi_auth_json(200,['ok'=>true,'id'=>$id,'secret'=>$secret,
    'loginUrl'=>'/api/janggi/google-start.php?client=android&webview_pair='.$id]);
 }
 if($action==='poll'){
  $id=$data['id']??'';$secret=$data['secret']??'';
  if(!is_string($id)||!preg_match('/^[0-9a-f]{48}$/D',$id)||
     !is_string($secret)||!preg_match('/^[0-9a-f]{64}$/D',$secret))
    janggi_auth_json(400,['ok'=>false]);
  $db->beginTransaction();
  $q=$db->prepare('SELECT secret_hash,account_key,state FROM gamelab_janggi_webview_pairs
      WHERE pair_hash=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
  $q->execute([hash('sha256',$id)]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!is_array($row)||!hash_equals((string)$row['secret_hash'],hash('sha256',$secret))){
    $db->rollBack();janggi_auth_json(410,['ok'=>false,'message'=>'로그인 연결이 만료되었습니다.']);}
  if($row['state']!=='approved'){
    $db->commit();janggi_auth_json(200,['ok'=>true,'status'=>'pending']);}
  $key=(string)$row['account_key'];
  if(!preg_match('/^[0-9a-f]{64}$/D',$key)){ $db->rollBack();janggi_auth_json(500,['ok'=>false]);}
  $del=$db->prepare('DELETE FROM gamelab_janggi_webview_pairs WHERE pair_hash=?');
  $del->execute([hash('sha256',$id)]);
  // Issue cookie only to the WebView that knows the unguessable secret.
  janggi_issue_device_login($key);
  $db->commit();
  janggi_auth_json(200,['ok'=>true,'status'=>'complete','user'=>['id'=>$key]]);
 }
 janggi_auth_json(400,['ok'=>false]);
} catch(Throwable $e){
 if(isset($db)&&$db->inTransaction())$db->rollBack();
 error_log('janggi_webview_pair: DB failure');
 janggi_auth_json(503,['ok'=>false,'message'=>'로그인 연결 서버를 확인해 주세요.']);
}
