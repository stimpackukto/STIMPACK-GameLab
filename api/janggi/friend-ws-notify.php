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

function janggi_friend_ws_online_accounts(array $accounts): ?array {
 $secret=getenv('JANGGI_FRIEND_WS_SECRET')?:'';
 if(strlen($secret)<32)return null;
 $accounts=array_values(array_unique(array_filter($accounts,static fn($a)=>
  is_string($a)&&preg_match('/^[a-f0-9]{64}$/D',$a))));
 if(count($accounts)>100)$accounts=array_slice($accounts,0,100);
 $body=json_encode(['accounts'=>$accounts],JSON_UNESCAPED_SLASHES);
 if($body===false)return null;
 $headers="Content-Type: application/json\r\nX-Janggi-Signature: ".
  hash_hmac('sha256',$body,$secret)."\r\n";
 $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,
  'content'=>$body,'timeout'=>0.65,'ignore_errors'=>true]]);
 $response=@file_get_contents('http://127.0.0.1:18771/presence',false,$ctx);
 if(!is_string($response))return null;
 $data=json_decode($response,true);
 return is_array($data)&&($data['ok']??false)===true&&is_array($data['online']??null)
  ?$data['online']:null;
}
