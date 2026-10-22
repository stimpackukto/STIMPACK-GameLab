<?php
declare(strict_types=1);

// Anonymous, opt-in-only completed-match ingest for Janggi guests.
// Do not start a session, set cookies, or create guest account records.
// A single all-zero account_key marks guest records in the existing table.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function guest_result(int $status,array $body): never {
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    header('Allow: POST');guest_result(405,['ok'=>false,'message'=>'POST만 허용됩니다.']);
}
// Same-origin browser interaction is required; credentials are NOT used.
if (($_SERVER['HTTP_HOST']??'')!=='stimpack.pe.kr' ||
    ($_SERVER['HTTP_ORIGIN']??'')!=='https://stimpack.pe.kr' ||
    ($_SERVER['HTTP_X_STIMPACK_REQUEST']??'')!=='janggi-guest' ||
    !in_array($_SERVER['HTTP_SEC_FETCH_SITE']??'', ['same-origin',''],true)) {
    guest_result(403,['ok'=>false,'message'=>'허용되지 않은 기보 전송 요청입니다.']);
}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')) {
    guest_result(415,['ok'=>false,'message'=>'JSON 형식만 허용됩니다.']);
}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>98304) {
    guest_result(413,['ok'=>false,'message'=>'기보 크기를 초과했습니다.']);
}
$raw=file_get_contents('php://input',false,null,0,98305);
if (!is_string($raw)||strlen($raw)>98304) {
    guest_result(413,['ok'=>false,'message'=>'기보 크기를 초과했습니다.']);
}
$body=json_decode($raw,true,32);
if (!is_array($body)||($body['consent']??false)!==true ||
    ($body['consentVersion']??'')!=='janggi-ai-training-v1') {
    guest_result(403,['ok'=>false,'message'=>'기보 전송 동의가 필요합니다.']);
}
$r=$body['record']??null;
if (!is_array($r)||($r['completed']??false)!==true ||
    !in_array($r['winner']??null,['blue','red'],true) ||
    !is_string($r['id']??null) ||
    preg_match('/^[A-Za-z0-9-]{8,64}$/D',$r['id'])!==1) {
    guest_result(422,['ok'=>false,'message'=>'완료된 대국 정보가 올바르지 않습니다.']);
}
$moves=$r['moves']??null;
if (!is_array($moves)||!array_is_list($moves)||count($moves)>240) {
    guest_result(422,['ok'=>false,'message'=>'착수 기록이 올바르지 않습니다.']);
}
$cleanMoves=[];
foreach($moves as $index=>$move) {
    if (!is_array($move)||($move['side']??null)!==($index%2===0?'blue':'red')) {
        guest_result(422,['ok'=>false,'message'=>'착수 순서가 올바르지 않습니다.']);
    }
    $pass=($move['pass']??false)===true;
    $from=$move['from']??null;$to=$move['to']??null;
    if (!$pass && (!is_int($from)||!is_int($to)||
        $from<0||$from>89||$to<0||$to>89||$from===$to)) {
        guest_result(422,['ok'=>false,'message'=>'착수 좌표가 올바르지 않습니다.']);
    }
    $cleanMoves[]=['side'=>$move['side'],'pass'=>$pass,
        'from'=>$pass?null:$from,'to'=>$pass?null:$to];
}
if (!in_array($r['timePreset']??null,
    ['blitz3','blitz5','standard10','rapid15','janggi15','long30','classic90'],true)||
    !in_array($r['engineChoice']??null,['fairy-stockfish','minimax'],true)) {
    guest_result(422,['ok'=>false,'message'=>'대국 설정이 올바르지 않습니다.']);
}
$pattern=is_array($r['pattern']??null)?$r['pattern']:[];
$record=[
    'id'=>$r['id'],'winner'=>$r['winner'],
    'detail'=>mb_substr((string)($r['detail']??''),0,140),
    'timePreset'=>$r['timePreset'],'engineChoice'=>$r['engineChoice'],
    'pattern'=>[
        'blue'=>($pattern['blue']??'outer')==='inner'?'inner':'outer',
        'red'=>($pattern['red']??'outer')==='inner'?'inner':'outer'
    ],'moves'=>$cleanMoves
];

// Optional transient abuse throttle. IP hashes never enter the training DB.
// Nginx rate limiting can be enabled separately for production traffic.
if (function_exists('apcu_add')&&filter_var(ini_get('apc.enabled'),FILTER_VALIDATE_BOOLEAN)) {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $bucket='jg_guest_hour:'.hash('sha256',$ip.':'.gmdate('YmdH'));
    if (apcu_add($bucket,0,3700)) { /* first request this hour */ }
    $hits=apcu_inc($bucket,1,$ok);
    if ($ok&&$hits>30)guest_result(429,['ok'=>false,'message'=>'잠시 후 다시 시도해 주세요.']);
}

require_once dirname(__DIR__,2).'/includes/db.php';
try {
    $db=gamelab_db();
    $encoded=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $stmt=$db->prepare('INSERT INTO gamelab_janggi_training_records
      (account_key,game_id,consent_version,record_json,created_at)
      VALUES (?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE game_id=game_id');
    $stmt->execute([str_repeat('0',64),$r['id'],'janggi-ai-training-v1',$encoded]);
    guest_result(200,['ok'=>true,'stored'=>true]);
}catch(Throwable $e){
    error_log('janggi_guest_record_ingest: failed');
    guest_result(503,['ok'=>false,'message'=>'게스트 기보 저장 서버가 준비되지 않았습니다.']);
}
