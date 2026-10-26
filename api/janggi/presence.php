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
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/janggi_device_session.php';
try {
 $me=janggi_authenticated_account_key();
 if(!$me)presence_reply(401,['ok'=>false,'error'=>'login_required']);
 $db=gamelab_db();
 $db->beginTransaction();
 // Expire presence after 120 seconds; returning within the window is not another sign-in.
 $q=$db->prepare("INSERT INTO janggi_friend_presence(account_key,online_since,last_seen)
 VALUES(?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
 ON DUPLICATE KEY UPDATE
 online_since=IF(last_seen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND), UTC_TIMESTAMP(), online_since),
 last_seen=UTC_TIMESTAMP()");
 $q->execute([$me]);
 $db->commit();
 $q=$db->prepare("SELECT p.account_key AS peer, UNIX_TIMESTAMP(p.online_since) AS online_since,
  CASE WHEN f.sender_key=? THEN f.receiver_name ELSE f.sender_name END AS name
 FROM janggi_friend_requests f
 JOIN janggi_friend_presence p ON p.account_key =
  CASE WHEN f.sender_key=? THEN f.receiver_key ELSE f.sender_key END
 WHERE (f.sender_key=? OR f.receiver_key=?) AND f.status='accepted'
 AND p.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND)
 ORDER BY p.last_seen DESC LIMIT 100");
 $q->execute([$me,$me,$me,$me]);
 presence_reply(200,['ok'=>true,'friends'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
} catch(Throwable $e) {
 if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();
 error_log('janggi_presence_failed');
 presence_reply(503,['ok'=>false,'error'=>'server_unavailable']);
}
