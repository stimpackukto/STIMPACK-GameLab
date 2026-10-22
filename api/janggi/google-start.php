<?php
declare(strict_types=1);
/**
 * Dedicated Janggi Sign in with Google screen.
 * Intentionally served outside the COEP-isolated WASM game documents:
 * Google's GIS iframe works here while Fairy-Stockfish remains isolated.
 */
require_once dirname(__DIR__,2).'/includes/janggi_google_auth.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
    header('Allow: GET');http_response_code(405);exit;
}
if(($_SERVER['HTTP_HOST']??'')!=='stimpack.pe.kr'){http_response_code(403);exit;}
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
// Login JavaScript is embedded here and Google injects identity iframes.
// No game COEP/COOP is set on this dedicated PHP login page.
$client=janggi_google_client_id();
if($client===''){http_response_code(503);
    echo '<html lang="ko"><meta charset="utf-8"><p>Google 인증 설정이 필요합니다. 관리자에게 문의하세요.</p></html>';
    exit;
}
janggi_google_session();
$nonce=bin2hex(random_bytes(24));
$_SESSION['janggi_google_nonce']=$nonce;
session_write_close();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0d1b2c">
<title>STIMPACK 장기 · Google 로그인</title>
<style>
*{box-sizing:border-box}
body{font:17px/1.6 system-ui,'Malgun Gothic',sans-serif;color:#f8ebd2;background:#111c31;margin:0;min-height:100svh;display:grid;place-items:center;padding:20px}
main{width:min(100%,440px);padding:28px 22px 32px;border-radius:17px;background:#1b2942;border:1px solid #ab884d;text-align:center}
h1{font-size:25px;color:#f8d999;margin:0 0 10px}
p{margin:13px 0 23px;color:#d9ceb8}
#google-button{display:flex;justify-content:center;min-height:44px}
#status{min-height:50px;margin-top:18px;font-size:15px;color:#ffe1a7}
a{color:#c6e2ff;text-decoration:none}
small{display:block;color:#d2c6b3;margin-top:16px}
</style>
</head>
<body>
<main>
<h1>STIMPACK 장기</h1>
<p>AI 학습용 기보 전송에 동의하고<br>Google 계정을 연결합니다.</p>
<div id="google-button" aria-label="Google 계정으로 로그인"></div>
<div id="status" role="status">Google 로그인 준비 중...</div>
<small>로그인 완료 후 장기 게임으로 자동 복귀합니다.</small>
<p><a href="/games/janggi/">취소하고 장기로 돌아가기</a></p>
</main>
<script>
const GOOGLE_CLIENT_ID=<?php echo json_encode($client,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const LOGIN_NONCE=<?php echo json_encode($nonce,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const statusNode=document.getElementById('status');
let inFlight=false;
window.handleJanggiGoogleCredential=async function(reply){
  if(inFlight||typeof reply?.credential!=='string')return;
  inFlight=true;statusNode.textContent='Google 계정을 확인하고 있습니다...';
  try{
    const response=await fetch('/api/janggi/google-login.php',{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json',
        'X-STIMPACK-Request':'janggi-google'},
      body:JSON.stringify({credential:reply.credential,consent:true,
        consentVersion:'janggi-ai-training-v1'})
    });
    const result=await response.json();
    if(!response.ok||result?.ok!==true||!result.user?.id)
      throw Error(result?.message||'Google 인증에 실패했습니다.');
    // Only the validated public profile and local consent are stored.
    // No Google credential, access token or PHP session id enters storage.
    const module=await import('/games/janggi/src/account-link.js');
    module.applyVerifiedAccount(localStorage,result.user,{enableTraining:true});
    location.replace('/games/janggi/');
  }catch(error){
    statusNode.textContent=String(error?.message||error);
    inFlight=false;
  }
};
window.initJanggiGoogle=function(){
  if(!window.google?.accounts?.id){statusNode.textContent='Google 로그인 라이브러리를 불러오지 못했습니다.';return;}
  google.accounts.id.initialize({
    client_id:GOOGLE_CLIENT_ID,
    nonce:LOGIN_NONCE,
    callback:window.handleJanggiGoogleCredential,
    auto_select:false,
    use_fedcm_for_button:true,
    context:'signin'
  });
  google.accounts.id.renderButton(document.getElementById('google-button'),{
    type:'standard',size:'large',theme:'filled_blue',
    text:'signin_with',shape:'rectangular',width:300,locale:'ko'
  });
  statusNode.textContent='Google 계정을 선택하면 자동으로 연결됩니다.';
  // Where supported, show the Google account chooser immediately; the
  // official button remains as a fallback when One Tap is suppressed.
  try{google.accounts.id.prompt();}catch(error){console.info('Google button remains available');}
};
</script>
<script async defer src="https://accounts.google.com/gsi/client" onload="initJanggiGoogle()" onerror="document.getElementById('status').textContent='Google 로그인 서버에 연결하지 못했습니다.'"></script>
</body>
</html>
