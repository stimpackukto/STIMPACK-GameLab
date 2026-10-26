<?php
declare(strict_types=1);

/**
 * Janggi ONLY Google Identity Services authentication. This does not use
 * GAME LAB's google_user PHP session, OAuth redirects, or account DB.
 */
function janggi_google_client_id(): string {
    $value=getenv('JANGGI_GOOGLE_CLIENT_ID');
    if (!is_string($value)||$value==='') {
        $value=@file_get_contents('/etc/stimpack/janggi_google_client_id');
    }
    // Optional zero-setup fallback: reuse the existing public OAuth client ID,
    // but NEVER the old GAME LAB login flow, session or OAuth client secret.
    if(!is_string($value)||trim($value)===''){
        $old='/etc/stimpack/google_oauth.php';
        if(is_file($old)){
            $cfg=@include $old;
            if(is_array($cfg))$value=$cfg['client_id']??'';
        }
    }
    $value=trim(is_string($value)?$value:'');
    return preg_match('/^[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D',$value)?$value:'';
}

function janggi_google_session(): void {
    if (session_status()===PHP_SESSION_ACTIVE) {
        if (session_name()!=='STIMPACK_JANGGI_AUTH') {
            throw new RuntimeException('Conflicting PHP session');
        }
        return;
    }
    session_name('STIMPACK_JANGGI_AUTH');
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    // PHP session is for short desktop authorization/Google login nonce only.
    // Long-lived TWA logins are hashed, revocable DB tokens instead.
    ini_set('session.gc_maxlifetime',(string)max(1800,(int)ini_get('session.gc_maxlifetime')));
    session_set_cookie_params([
        'lifetime'=>0,'path'=>'/api/janggi/','secure'=>true,
        'httponly'=>true,'samesite'=>'Lax'
    ]);
    session_start();
}

function janggi_google_origin_required(): bool {
    $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    $fetch=strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??''));
    return $host==='stimpack.pe.kr'&&
        $origin==='https://stimpack.pe.kr'&&
        ($fetch===''||$fetch==='same-origin');
}

function janggi_google_claim_decode(string $value): ?array {
    if($value===''||preg_match('/^[A-Za-z0-9_-]+$/D',$value)!==1)return null;
    $padded=strtr($value,'-_','+/');
    $padded.=str_repeat('=',(4-strlen($padded)%4)%4);
    $json=base64_decode($padded,true);
    if(!is_string($json)||strlen($json)>8192)return null;
    $data=json_decode($json,true,32);
    return is_array($data)?$data:null;
}

function janggi_google_certs(): array {
    // Google rotates its signing keys. Never persist certificates indefinitely.
    $cacheKey='janggi_google_pem_certs_v1';
    if (function_exists('apcu_fetch')) {
        $cached=apcu_fetch($cacheKey,$ok);
        if($ok&&is_array($cached))return $cached;
    }
    $url='https://www.googleapis.com/oauth2/v1/certs';
    $response=false;
    if (function_exists('curl_init')) {
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>6,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_MAXREDIRS=>0
        ]);
        $response=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($status!==200)$response=false;
    } else {
        $context=stream_context_create(['http'=>['timeout'=>6,'follow_location'=>0],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $response=@file_get_contents($url,false,$context);
    }
    if(!is_string($response)||strlen($response)>32768)throw new RuntimeException('Google certificate service unavailable');
    $certs=json_decode($response,true,8);
    if(!is_array($certs)||count($certs)<1||count($certs)>8)throw new RuntimeException('Google certificates invalid');
    foreach($certs as $kid=>$pem){
        if(!is_string($kid)||!is_string($pem)||!str_contains($pem,'BEGIN CERTIFICATE'))
            throw new RuntimeException('Google certificate format invalid');
    }
    if(function_exists('apcu_store'))apcu_store($cacheKey,$certs,1800);
    return $certs;
}

function janggi_verify_google_token(string $jwt,string $audience,string $expectedNonce): ?array {
    if(strlen($jwt)<100||strlen($jwt)>8192||$expectedNonce==='')return null;
    $sections=explode('.',$jwt);
    if(count($sections)!==3)return null;
    [$head,$body,$signature]=$sections;
    $header=janggi_google_claim_decode($head);
    $claims=janggi_google_claim_decode($body);
    if(!$header||!$claims||($header['alg']??'')!=='RS256')return null;
    $kid=$header['kid']??null;
    if(!is_string($kid)||strlen($kid)>128)return null;
    $certs=janggi_google_certs();
    $cert=$certs[$kid]??null;
    if(!is_string($cert))return null;
    $sig=base64_decode(strtr($signature,'-_','+/'),true);
    if(!is_string($sig)||openssl_verify($head.'.'.$body,$sig,$cert,OPENSSL_ALGO_SHA256)!==1)return null;
    $now=time();
    if(!in_array($claims['iss']??null,['accounts.google.com','https://accounts.google.com'],true))return null;
    if(!is_string($claims['aud']??null)||!hash_equals($audience,$claims['aud']))return null;
    if(!is_numeric($claims['exp']??null)||$claims['exp']<=$now)return null;
    if(!is_numeric($claims['iat']??null)||$claims['iat']>$now+60)return null;
    if(isset($claims['nbf'])&&(!is_numeric($claims['nbf'])||$claims['nbf']>$now+60))return null;
    if(!is_string($claims['nonce']??null)||!hash_equals($expectedNonce,$claims['nonce']))return null;
    if(!is_string($claims['sub']??null)||!preg_match('/^[0-9]{8,32}$/D',$claims['sub']))return null;
    // Android ID tokens may have an authorized-party (azp) client ID that
    // differs from the web/backend audience (aud). The signed audience and
    // one-time nonce are still verified above; do not equate azp with aud.
    if(isset($claims['azp'])&&(!is_string($claims['azp'])||
        !preg_match('/^[0-9]+-[A-Za-z0-9_-]+\\.apps\\.googleusercontent\\.com$/D',$claims['azp'])))return null;
    return [
        'sub'=>$claims['sub'],
        'name'=>mb_substr((string)($claims['name']??''),0,120),
        'email'=>mb_substr((string)($claims['email']??''),0,180),
        'picture'=>mb_substr((string)($claims['picture']??''),0,512)
    ];
}

function janggi_auth_json(int $status,array $result): never {
    http_response_code($status);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function janggi_auth_headers(): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Vary: Cookie');
}
