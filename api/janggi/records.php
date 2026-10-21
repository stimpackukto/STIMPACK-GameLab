<?php
declare(strict_types=1);

// Training data API: receive ONLY finished, explicitly consented Janggi records.
// No account profile, device analytics, IP, cookies or access tokens are stored.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Vary: Cookie');

function out(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method!=='POST' && $method!=='DELETE') {
    header('Allow: POST, DELETE'); out(405,['ok'=>false,'message'=>'지원하지 않는 요청입니다.']);
}
// CSRF defense in depth: JSON/custom header plus matching same-origin origin/fetch metadata.
if (($_SERVER['HTTP_X_STIMPACK_REQUEST'] ?? '') !== 'janggi') {
    out(403,['ok'=>false,'message'=>'요청 헤더가 올바르지 않습니다.']);
}
$ctype=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
if ($method==='POST' && !str_starts_with($ctype,'application/json')) {
    out(415,['ok'=>false,'message'=>'JSON만 허용됩니다.']);
}
$fetchSite=strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??''));
if ($fetchSite!=='' && $fetchSite!=='same-origin') {
    out(403,['ok'=>false,'message'=>'동일 출처에서만 사용할 수 있습니다.']);
}
$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
$host=(string)($_SERVER['HTTP_HOST']??'');
if ($origin!=='' && $origin!=='https://'.$host) {
    out(403,['ok'=>false,'message'=>'잘못된 요청 출처입니다.']);
}
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
$user=$_SESSION['google_user']??null;
if (!is_array($user)||!is_string($user['sub']??null)||trim($user['sub'])==='') {
    out(401,['ok'=>false,'message'=>'GAME LAB Google 로그인이 필요합니다.']);
}
require_once dirname(__DIR__, 2) . '/includes/janggi_account_key.php';
$key=janggi_pseudonymous_account_id((string)$user['sub']);
if ($key===null)out(503,['ok'=>false,'message'=>'장기 계정 서버 설정이 필요합니다.']);

// No extra data about the user is persisted. Only a pseudonymous account key
// attached to a consented record, so the account can delete its own records.
require_once dirname(__DIR__,2).'/includes/db.php';
try {$db=gamelab_db();}
catch (Throwable $e) {
    error_log('janggi_record_db: database not available');
    out(503,['ok'=>false,'message'=>'기보 서버가 준비되지 않았습니다.']);
}
if ($method==='DELETE') {
    try {
        $stmt=$db->prepare('DELETE FROM gamelab_janggi_training_records WHERE account_key=?');
        $stmt->execute([$key]);
        out(200,['ok'=>true,'deleted'=>$stmt->rowCount()]);
    }catch(Throwable $e){
        error_log('janggi_record_delete: query failed');
        out(503,['ok'=>false,'message'=>'저장된 기보를 삭제하지 못했습니다.']);
    }
}
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if ($length>98304)out(413,['ok'=>false,'message'=>'기보 크기가 너무 큽니다.']);
$raw=file_get_contents('php://input',false,null,0,98305);
if (!is_string($raw)||strlen($raw)>98304)out(413,['ok'=>false,'message'=>'기보 크기 제한을 초과했습니다.']);
$body=json_decode($raw,true,32);
if (!is_array($body) || ($body['consent']??false)!==true ||
    ($body['consentVersion']??'')!=='janggi-ai-training-v1') {
    out(403,['ok'=>false,'message'=>'AI 학습용 기보 수집에 대한 명시적인 동의가 없습니다.']);
}
$r=$body['record']??null;
if (!is_array($r) || ($r['completed']??false)!==true ||
    !in_array($r['winner']??'', ['blue','red'],true) ||
    !is_string($r['id']??null) || !preg_match('/^[a-zA-Z0-9-]{8,64}$/D',$r['id'])) {
    out(422,['ok'=>false,'message'=>'완료된 대국 정보가 올바르지 않습니다.']);
}
$moves=$r['moves']??null;
if (!is_array($moves)||count($moves)<1||count($moves)>240) {
    out(422,['ok'=>false,'message'=>'기보 수순 개수가 올바르지 않습니다.']);
}
$cleanMoves=[];
foreach($moves as $index=>$move) {
    if (!is_array($move))out(422,['ok'=>false,'message'=>'착수 데이터가 올바르지 않습니다.']);
    $side=$move['side']??null;
    // First side is blue and turns alternate. Full legality is a future audit step.
    if ($side!==($index%2===0?'blue':'red'))out(422,['ok'=>false,'message'=>'착수 순서가 올바르지 않습니다.']);
    $pass=($move['pass']??false)===true;
    $from=$move['from']??null;$to=$move['to']??null;
    if (!$pass&&(!is_int($from)||$from<0||$from>89||!is_int($to)||$to<0||$to>89||$from===$to))
        out(422,['ok'=>false,'message'=>'착수 좌표가 올바르지 않습니다.']);
    $cleanMoves[]=[
        'side'=>$side,'pass'=>$pass,
        'from'=>$pass?null:$from,'to'=>$pass?null:$to
    ];
}
if (!in_array($r['timePreset']??'', ['blitz3','blitz5','standard10','rapid15','janggi15','long30','classic90'],true) ||
    !in_array($r['engineChoice']??'', ['fairy-stockfish','minimax'],true)) {
    out(422,['ok'=>false,'message'=>'대국 설정 정보가 올바르지 않습니다.']);
}
$pattern=$r['pattern']??[];
$record=[
    'id'=>$r['id'],'winner'=>$r['winner'],
    'detail'=>mb_substr((string)($r['detail']??''),0,140),
    'timePreset'=>$r['timePreset'],'engineChoice'=>$r['engineChoice'],
    'pattern'=>[
       'blue'=>($pattern['blue']??'outer')==='inner'?'inner':'outer',
       'red'=>($pattern['red']??'outer')==='inner'?'inner':'outer'
    ],'moves'=>$cleanMoves
];
try{
    $encoded=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $stmt=$db->prepare('INSERT INTO gamelab_janggi_training_records
      (account_key,game_id,consent_version,record_json,created_at)
      VALUES (?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE game_id=game_id');
    $stmt->execute([$key,$r['id'],'janggi-ai-training-v1',$encoded]);
    out(200,['ok'=>true,'stored'=>true]);
}catch(Throwable $e){
    error_log('janggi_record_insert: database query failed');
    out(503,['ok'=>false,'message'=>'기보 저장 서버가 준비되지 않았습니다.']);
}
