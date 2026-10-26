<?php
// Reusable internal fire-and-forget notifier. Never expose the shared secret to browsers.
declare(strict_types=1);
function janggi_friend_ws_notify(string $account,int $invitationId,string $event):void {
 $secret=getenv('JANGGI_FRIEND_WS_SECRET')?:'';
 if(strlen($secret)<32||!preg_match('/^[a-f0-9]{64}$/D',$account))return;
 if(!in_array($event,['invite','accept','decline','cancel'],true))return;
 $body=json_encode(['account'=>$account,'id'=>$invitationId,'event'=>$event],JSON_UNESCAPED_SLASHES);
 if(!$body)return;
 $url='http://127.0.0.1:18771/notify';
 $headers="Content-Type: application/json\r\nX-Janggi-Signature: ".hash_hmac('sha256',$body,$secret)."\r\n";
 $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,
  'content'=>$body,'timeout'=>0.3,'ignore_errors'=>true]]);
 @file_get_contents($url,false,$ctx);
}
