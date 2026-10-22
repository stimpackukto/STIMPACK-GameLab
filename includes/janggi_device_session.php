<?php
declare(strict_types=1);
// Long-lived Android TWA sign-in; the cookie contains an opaque random value.
// Only a SHA-256 hash of the token and an HMAC account ID are stored in MySQL.
// PHP's short-lived session files are never used as the Android remember-me store.
const JANGGI_DEVICE_COOKIE='STIMPACK_JANGGI_DEVICE';
const JANGGI_DEVICE_DAYS=365;
const JANGGI_WEB_IDLE_SECONDS=1800;

function janggi_device_cookie_value(): ?string {
    $raw=$_COOKIE[JANGGI_DEVICE_COOKIE]??'';
    return is_string($raw)&&preg_match('/^[0-9a-f]{64}$/D',$raw)?$raw:null;
}
function janggi_device_set_cookie(string $value,int $until): void {
    setcookie(JANGGI_DEVICE_COOKIE,$value,[
      'expires'=>$until,'path'=>'/api/janggi/','secure'=>true,
      'httponly'=>true,'samesite'=>'Lax'
    ]);
}
function janggi_device_db(): PDO {
    require_once dirname(__DIR__).'/includes/db.php';
    return gamelab_db();
}
function janggi_issue_device_login(string $accountKey): void {
    if(!preg_match('/^[a-f0-9]{64}$/D',$accountKey))throw new RuntimeException('Bad Janggi account key');
    $token=bin2hex(random_bytes(32));
    $db=janggi_device_db();
    $stmt=$db->prepare('INSERT INTO gamelab_janggi_device_sessions
      (token_hash,account_key,created_at,last_seen_at,expires_at)
      VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(),INTERVAL 365 DAY))');
    $stmt->execute([hash('sha256',$token),$accountKey]);
    janggi_device_set_cookie($token,time()+JANGGI_DEVICE_DAYS*86400);
}
function janggi_device_account_key(): ?string {
    $token=janggi_device_cookie_value();
    if($token===null)return null;
    $hash=hash('sha256',$token);
    $db=janggi_device_db();
    $stmt=$db->prepare('SELECT account_key,last_seen_at
      FROM gamelab_janggi_device_sessions
      WHERE token_hash=? AND expires_at>UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([$hash]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)||!preg_match('/^[a-f0-9]{64}$/D',(string)($row['account_key']??'')))return null;
    // Refresh at most daily. Active Android app users stay logged in;
    // a lost or unused token cannot live forever.
    $last=strtotime((string)($row['last_seen_at']??'1970-01-01'));
    if($last===false||$last<time()-86400){
        $update=$db->prepare('UPDATE gamelab_janggi_device_sessions
          SET last_seen_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 365 DAY)
          WHERE token_hash=?');
        $update->execute([$hash]);
        janggi_device_set_cookie($token,time()+JANGGI_DEVICE_DAYS*86400);
    }
    return $row['account_key'];
}
function janggi_revoke_device_login(): void {
    $token=janggi_device_cookie_value();
    if($token!==null){
        $db=janggi_device_db();
        $stmt=$db->prepare('DELETE FROM gamelab_janggi_device_sessions WHERE token_hash=?');
        $stmt->execute([hash('sha256',$token)]);
    }
    janggi_device_set_cookie('',time()-3600);
}
function janggi_authenticated_account_key(): ?string {
    require_once __DIR__.'/janggi_google_auth.php';
    require_once __DIR__.'/janggi_account_key.php';
    // A valid opaque Android token takes precedence; invalid tokens cannot
    // revive a desktop session after it has expired.
    $tokenKey=janggi_device_account_key();
    if($tokenKey!==null)return $tokenKey;
    janggi_google_session();
    $user=$_SESSION['janggi_identity']??null;
    if(!is_array($user)||!is_string($user['sub']??null)||
       ($user['consentVersion']??'')!=='janggi-ai-training-v1')return null;
    $last=(int)($_SESSION['janggi_last_active']??0);
    if($last>0&&time()-$last>=JANGGI_WEB_IDLE_SECONDS){
        unset($_SESSION['janggi_identity'],$_SESSION['janggi_last_active']);
        return null;
    }
    $_SESSION['janggi_last_active']=time();
    return janggi_pseudonymous_account_id($user['sub']);
}
