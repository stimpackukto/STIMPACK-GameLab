<?php
declare(strict_types=1);

// The owner email contains an unguessable token. GET only delivers this page;
// the browser makes a single background POST so email URL prefetchers cannot
// change the application state by merely following the link.
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow', true);
header('X-Content-Type-Options: nosniff');

$idText = trim((string)($_GET['application_id'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
$isValid = $idText !== '' && ctype_digit($idText) && strlen($idText) <= 12
    && (int)$idText > 0 && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>개발테스트 오너 확인 - STIMPACK GAME LAB</title>
  <style>
    body{margin:0;background:#061626;color:#eef8ff;font-family:system-ui,"Malgun Gothic",sans-serif}
    main{max-width:520px;margin:12vh auto;padding:30px;background:#0d2439;border:1px solid #306285;border-radius:14px;text-align:center}
    h1{font-size:24px}
    p{line-height:1.7;color:#c5d9e8}
    a{color:#7ed1ff}
  </style>
</head>
<body>
  <main>
    <h1>STIMPACK GAME LAB</h1>
    <p id="confirmation-status" role="status" aria-live="polite">
      <?= $isValid ? '테스터 신청 오너 확인을 기록하고 있습니다…' : '이전 이메일 또는 유효하지 않은 확인 링크입니다. 새로 발송된 확인 메일을 이용해 주세요.' ?>
    </p>
    <p><a href="/test/">개발테스트로 이동</a></p>
    <?php if ($isValid): ?>
      <noscript>자동 확인을 위해 브라우저 JavaScript를 사용하도록 설정해 주세요.</noscript>
    <?php endif; ?>
  </main>
  <?php if ($isValid): ?>
  <script>
  (async function () {
    const status = document.getElementById('confirmation-status');
    const id = <?= json_encode((int)$idText) ?>;
    const token = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    try {
      const body = new URLSearchParams({application_id: String(id), token: token});
      const response = await fetch('/api/devtest/confirm.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        credentials: 'omit',
        cache: 'no-store',
        body: body.toString()
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.message || '확인 처리에 실패했습니다.');
      }
      status.textContent = result.message + ' 창을 닫으셔도 됩니다.';
      // Remove the secret from the address bar and browser-history entry.
      window.history.replaceState(null, '', '/test/confirm.php?done=1');
    } catch (error) {
      status.textContent = (error instanceof Error ? error.message : '확인 요청에 실패했습니다.')
        + ' 다시 이메일의 오너 확인 버튼을 눌러 주세요.';
    }
  }());
  </script>
  <?php endif; ?>
</body>
</html>
