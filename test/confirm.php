<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/devtest.php';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow', true);

$idText = trim((string)($_GET['application_id'] ?? ''));
if ($idText === '' || !ctype_digit($idText) || strlen($idText) > 12
    || (int)$idText < 1) {
    http_response_code(400);
    exit('신청 번호가 올바르지 않습니다.');
}
$applicationId = (int)$idText;
$viewer = gamelab_devtest_user();
if ($viewer === null) {
    // Login returns to /test/. That page forwards back to the confirmation.
    // Do not change Google OAuth redirect URL or expand the OAuth allowlist.
    $_SESSION['devtest_owner_confirm_pending'] = $applicationId;
    header('Location: /auth/google_login.php?return=%2Ftest%2F', true, 302);
    exit;
}

try {
    $db = gamelab_db();
    $application = gamelab_devtest_application($db, $applicationId);
    if ($application === null) {
        http_response_code(404);
        exit('신청 기록이 없습니다.');
    }
    if (!hash_equals((string)$application['owner_google_sub'], $viewer['sub'])) {
        http_response_code(403);
        exit('해당 앱을 등록한 구글 계정으로 로그인해야 신청을 확인할 수 있습니다.');
    }
} catch (Throwable $error) {
    error_log('[GAME LAB DEVTEST] owner confirm read: ' . $error->getMessage());
    http_response_code(503);
    exit('신청 내역을 불러오지 못했습니다.');
}

$pageTitle = '개발테스트 신청 오너 확인';
require dirname(__DIR__) . '/includes/header.php';
?>
<style>
.devtest-confirm-container{max-width:680px;margin:30px auto 80px;padding:26px;border:1px solid #275374;border-radius:14px;background:#0b2238;color:#e3f2ff}
.devtest-confirm-container h1{font-size:27px;margin:0 0 18px}
.devtest-confirm-container p{line-height:1.8;overflow-wrap:anywhere}
.devtest-confirm-status{color:#86deba;font-weight:700}
.devtest-confirm-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}
.devtest-confirm-button{background:#028be3;color:#fff;border:1px solid #159ee8;border-radius:9px;padding:12px 18px;cursor:pointer;font-weight:750;text-decoration:none}
.devtest-confirm-button.secondary{background:#102e45;border-color:#386580;color:#d4eafa}
</style>
<section class="section">
  <div class="container">
    <div class="devtest-confirm-container">
      <h1>테스터 신청 오너 확인</h1>
      <p><strong>앱:</strong> <?= e((string)$application['title']) ?></p>
      <p><strong>참여 신청 이메일:</strong> <?= e((string)$application['applicant_email']) ?></p>
      <?php if ($application['owner_confirmed_at'] !== null): ?>
        <p class="devtest-confirm-status">이미 오너 확인이 완료된 신청입니다.</p>
      <?php else: ?>
        <p>신청 내용을 확인했다면 아래 버튼을 눌러 주세요. 이 처리는 신청 이메일을 소유자가 확인했다는 뜻이며, Google Play 테스터 등록이나 앱 설치를 뜻하지 않습니다.</p>
        <form method="post" action="/api/devtest/confirm.php">
          <input type="hidden" name="csrf" value="<?= e(gamelab_csrf_token()) ?>">
          <input type="hidden" name="application_id" value="<?= $applicationId ?>">
          <button type="submit" class="devtest-confirm-button">오너 확인 완료</button>
        </form>
      <?php endif; ?>
      <div class="devtest-confirm-actions">
        <a class="devtest-confirm-button secondary" href="/test/?tab=recruiting#devtest-projects">개발테스트 목록으로</a>
      </div>
    </div>
  </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
