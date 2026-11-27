<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/devtest.php';

$pageTitle = '비공개 개발테스트';
$viewer = gamelab_devtest_user();
$validTabs = ['recruiting', 'testing', 'completed'];
$tab = (string)($_GET['tab'] ?? 'recruiting');
if (!in_array($tab, $validTabs, true)) {
    $tab = 'recruiting';
}
$tabNames = ['recruiting' => '모집 중', 'testing' => '테스트 중', 'completed' => '완료'];
$counts = ['recruiting' => 0, 'testing' => 0, 'completed' => 0];
$projects = [];
$active = null;
$joinedProjectIds = [];
$db = null;
$databaseReady = true;
$errorText = '';
$noticeText = [
    'created' => '앱이 모집 중 목록에 등록되었습니다.',
    'edited' => '등록 정보가 수정되었습니다.',
    'started' => '앱이 테스트 중 목록으로 이동했습니다.',
    'completed' => '테스트 완료로 처리했습니다. 이제 새 앱을 등록할 수 있습니다.',
    'joined' => '테스트 참여 신청이 접수되어 앱 등록자에게 이메일 발송을 요청했습니다.',
][(string)($_GET['saved'] ?? '')] ?? '';

$formMode = '';
$formProjectId = 0;
$formValues = ['title' => '', 'description' => '', 'recruited_count' => '0', 'target_count' => '12', 'contact_email' => $viewer['email'] ?? '', 'video_url' => '', 'play_url' => '', 'image_path' => ''];

if ($viewer !== null) {
    try {
        $db = gamelab_db();
        // The DB migration is performed on the server before this page is deployed.
        $db->query('SELECT id, contact_email, image_path, video_url, play_url FROM gamelab_devtest_projects LIMIT 0');
        $db->query('SELECT owner_google_sub FROM gamelab_devtest_active_owners LIMIT 0');
        $db->query('SELECT id, owner_confirmed_at, owner_confirm_token_hash FROM gamelab_devtest_applications LIMIT 0');
    } catch (Throwable $error) {
        $databaseReady = false;
        error_log('[GAME LAB DEVTEST] database initialization check failed: ' . $error->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($viewer === null) {
        http_response_code(403);
        exit('Google 로그인 후 사용할 수 있습니다.');
    }
    if (!$databaseReady || !($db instanceof PDO)) {
        http_response_code(503);
        exit('개발테스트 DB가 준비되지 않았습니다.');
    }
    if (!gamelab_verify_csrf((string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('요청이 만료되었습니다. 페이지를 새로고침해 주세요.');
    }

    $action = (string)($_POST['action'] ?? '');
    $postTab = (string)($_POST['tab'] ?? 'recruiting');
    if (in_array($postTab, $validTabs, true)) {
        $tab = $postTab;
    }

    $uploadedImagePath = null;
    try {
        if ($action === 'create') {
            // The mail destination is always the verified registration identity.
            $_POST['contact_email'] = $viewer['email'];
            $formMode = 'create';
            $formValues = [
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'recruited_count' => (string)($_POST['recruited_count'] ?? ''),
                'target_count' => (string)($_POST['target_count'] ?? ''),
                'contact_email' => (string)($_POST['contact_email'] ?? ''),
                'video_url' => (string)($_POST['video_url'] ?? ''),
                'play_url' => (string)($_POST['play_url'] ?? ''),
                'image_path' => '',
            ];
            $values = gamelab_devtest_form_values($_POST);
            $uploadedImagePath = gamelab_devtest_upload_image($_FILES['image'] ?? null);
            $values['image_path'] = $uploadedImagePath;
            gamelab_devtest_create($db, $viewer, $values);
            $uploadedImagePath = null;
            header('Location: /test/?tab=recruiting&saved=created#devtest-projects', true, 303);
            exit;
        }
        $id = gamelab_devtest_number($_POST['project_id'] ?? '', '앱 ID', 1, 100000000);
        if ($action === 'join') {
            gamelab_devtest_join($db, $viewer, $id, (string)($_POST['applicant_email'] ?? ''));
            header('Location: /test/?tab=recruiting&saved=joined#devtest-projects', true, 303);
            exit;
        }
        if ($action === 'edit') {
            $ownerProject = gamelab_devtest_owned_project($db, $id, $viewer['sub']);
            $_POST['contact_email'] = (string)$ownerProject['owner_email'];
            $formMode = 'edit';
            $formProjectId = $id;
            $formValues = [
                'title' => (string)($_POST['title'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'recruited_count' => (string)($_POST['recruited_count'] ?? ''),
                'target_count' => (string)($_POST['target_count'] ?? ''),
                'contact_email' => (string)($_POST['contact_email'] ?? ''),
                'video_url' => (string)($_POST['video_url'] ?? ''),
                'play_url' => (string)($_POST['play_url'] ?? ''),
                'image_path' => '',
            ];
            $values = gamelab_devtest_form_values($_POST);
            if (!empty($_POST['remove_image']) && !empty($_FILES['image']['name'])) {
                throw new RuntimeException('대표 이미지 변경과 삭제는 동시에 선택할 수 없습니다.');
            }
            $uploadedImagePath = gamelab_devtest_upload_image($_FILES['image'] ?? null);
            $values['image_path'] = $uploadedImagePath;
            $values['remove_image'] = !empty($_POST['remove_image']);
            gamelab_devtest_edit($db, $viewer, $id, $values);
            $uploadedImagePath = null;
            header('Location: /test/?tab=' . rawurlencode($tab) . '&saved=edited#devtest-projects', true, 303);
            exit;
        }
        if ($action === 'start') {
            gamelab_devtest_transition($db, $viewer, $id, 'start');
            header('Location: /test/?tab=testing&saved=started#devtest-projects', true, 303);
            exit;
        }
        if ($action === 'complete') {
            gamelab_devtest_transition($db, $viewer, $id, 'complete');
            header('Location: /test/?tab=completed&saved=completed#devtest-projects', true, 303);
            exit;
        }
        throw new RuntimeException('지원하지 않는 작업입니다.');
    } catch (RuntimeException $error) {
        gamelab_devtest_delete_image($uploadedImagePath);
        $errorText = $error->getMessage();
        http_response_code(422);
    } catch (Throwable $error) {
        gamelab_devtest_delete_image($uploadedImagePath);
        error_log('[GAME LAB DEVTEST] POST error: ' . $error->getMessage());
        $errorText = '처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
        http_response_code(500);
    }
}

if ($viewer !== null && $databaseReady && $db instanceof PDO) {
    try {
        $counts = gamelab_devtest_counts($db);
        $active = gamelab_devtest_active($db, $viewer['sub']);
        $projects = gamelab_devtest_list($db, $tab);
        $joinStmt = $db->prepare('SELECT project_id, owner_confirmed_at FROM gamelab_devtest_applications WHERE applicant_google_sub = ?');
        $joinStmt->execute([$viewer['sub']]);
        $joinedProjectIds = [];
        foreach ($joinStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $joinedProjectIds[(int)$row['project_id']] = $row['owner_confirmed_at'] !== null
                ? 'owner_confirmed'
                : 'applied';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if (isset($_GET['new']) && $active === null) {
                $formMode = 'create';
            }
            if (isset($_GET['edit'])) {
                $requestedId = gamelab_devtest_number($_GET['edit'], '앱 ID', 1, 100000000);
                $project = gamelab_devtest_owned_project($db, $requestedId, $viewer['sub']);
                if ($project['status'] === 'completed') {
                    throw new RuntimeException('완료된 앱은 수정할 수 없습니다.');
                }
                $tab = (string)$project['status'];
                $projects = gamelab_devtest_list($db, $tab);
                $formMode = 'edit';
                $formProjectId = $requestedId;
                $formValues = [
                    'title' => (string)$project['title'],
                    'description' => (string)$project['description'],
                    'recruited_count' => (string)$project['recruited_count'],
                    'target_count' => (string)$project['target_count'],
                    'contact_email' => (string)$project['owner_email'],
                    'video_url' => (string)($project['video_url'] ?? ''),
                    'play_url' => (string)($project['play_url'] ?? ''),
                    'image_path' => (string)($project['image_path'] ?? ''),
                ];
            }
        }
    } catch (RuntimeException $error) {
        $errorText = $error->getMessage();
    } catch (Throwable $error) {
        error_log('[GAME LAB DEVTEST] SELECT error: ' . $error->getMessage());
        $databaseReady = false;
        $errorText = '테스트 프로젝트를 불러오지 못했습니다.';
    }
}

require dirname(__DIR__) . '/includes/header.php';
?>
<style>
.devtest-hero{padding-top:52px;padding-bottom:48px}
.devtest-hero .section-kicker{color:#a8caed;letter-spacing:1.7px}
.devtest-hero h1{font-size:42px;line-height:1.25;margin:12px 0 18px}
.devtest-intro{margin-bottom:24px}
.devtest-tabs{margin-bottom:26px}
.devtest-tab{position:relative;color:inherit}
.devtest-tab.is-selected{border-color:#16b5fb;box-shadow:0 0 0 1px rgba(19,185,255,.2);background:#0d2a43}
.devtest-tab .devtest-count{margin-left:auto;color:#8ed5ff;font-size:17px;font-weight:800;white-space:nowrap}
.devtest-actions{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin:18px 0}
.devtest-actions h2{font-size:26px;margin:0}
.devtest-button{display:inline-flex;align-items:center;justify-content:center;padding:10px 17px;border:1px solid #258ed3;border-radius:9px;background:#0a3d62;color:white;font-weight:800;cursor:pointer;text-decoration:none;min-height:44px}
.devtest-button:hover{background:#106195}
.devtest-button.primary{background:#028be3;border-color:#029fe4;color:white}
.devtest-button.complete{background:#167953;border-color:#239e70}
.devtest-button.muted{background:#12283b;border-color:#2c5776}
.devtest-card{position:relative;padding:24px;border:1px solid #234d6d;border-radius:13px;background:linear-gradient(140deg,#0e263c,#091c2f);margin-bottom:14px}
.devtest-card h3{font-size:22px;margin:4px 0 10px}
@media(min-width:951px){
  .devtest-card.has-play-qr h3{max-width:calc(50% - 20px)}
}
.devtest-card p{white-space:pre-line;color:#b1c8d9;margin:12px 0;overflow-wrap:anywhere}
.devtest-card .devtest-description{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;margin:12px 0 0}
.devtest-card .devtest-description.is-expanded{white-space:pre-line;overflow:visible;text-overflow:clip}
.devtest-card-toolbar{display:flex;align-items:center;flex-wrap:nowrap;gap:9px;margin-top:8px;overflow-x:auto;padding-bottom:5px}
.devtest-card-toolbar>*{flex:0 0 auto}
.devtest-card-toolbar .devtest-button{white-space:nowrap}
.devtest-card-toolbar form{display:inline-flex;margin:0}
.devtest-card-toolbar a.devtest-button{color:#fff;text-decoration:none;overflow-wrap:normal}
.devtest-card .devtest-play-qr{position:absolute;left:50%;top:14px;display:inline-flex;align-items:center;justify-content:center;width:65px;height:65px;padding:5px;box-sizing:border-box;background:#fff;border:1px solid #bdcbd6;border-radius:2px;text-decoration:none;z-index:1}
.devtest-play-qr-code,.devtest-play-qr-code img,.devtest-play-qr-code canvas{display:block;width:60px;height:60px;min-width:60px;min-height:60px}
.devtest-card .devtest-play-qr:hover{border-color:#14b8ff;box-shadow:0 0 0 2px rgba(20,184,255,.22)}
@media(max-width:950px){
  .devtest-card .devtest-play-qr{position:static;float:right;margin:0 12px 10px}
}


.devtest-meta{display:flex;gap:9px 20px;flex-wrap:wrap;color:#86acc9;font-size:13px}
.devtest-card-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:18px}
.devtest-note{font-size:13px;color:#98b9d1}
.devtest-alert{padding:14px 17px;border-radius:10px;border:1px solid #a94a58;background:#321d2b;color:#ffdfe4;margin:0 0 18px}
.devtest-success{padding:14px 17px;border-radius:10px;border:1px solid #267e61;background:#102d2a;color:#c8f9e5;margin:0 0 18px}
.devtest-form{border:1px solid #2b648d;background:#0b2238;border-radius:14px;padding:24px;margin:22px 0}
.devtest-form h3{font-size:23px;margin:0 0 18px}
.devtest-field{display:flex;flex-direction:column;gap:7px;margin-bottom:17px}
.devtest-field label{font-weight:750;color:#e2f0fa}
.devtest-field input,.devtest-field textarea{display:block;width:100%;background:#061626;color:#fff;border:1px solid #356585;padding:12px;border-radius:9px;font:inherit}
.devtest-field input:focus,.devtest-field textarea:focus{outline:2px solid #159bdd;outline-offset:1px}
.devtest-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:17px}
.devtest-form-grid.media{grid-template-columns:repeat(2,minmax(0,1fr))}
.devtest-field small{font-size:12px;color:#9bbbd1;line-height:1.5}
.devtest-cover{width:210px;max-width:35%;aspect-ratio:4/3;object-fit:cover;float:right;margin:0 0 12px 20px;border-radius:10px;border:1px solid #356585}
.devtest-card{display:flow-root}
.devtest-links{display:flex;gap:12px 20px;flex-wrap:wrap;margin:13px 0;align-items:center}
.devtest-links a{color:#80d5ff;text-decoration:underline;overflow-wrap:anywhere}
.devtest-image-preview{max-width:200px;max-height:150px;object-fit:contain;border:1px solid #356585;border-radius:8px}
.devtest-join-dialog{width:min(95vw,470px);padding:24px;background:#0b2238;color:#f5faff;border:1px solid #3f7197;border-radius:14px;box-shadow:0 24px 80px rgba(0,0,0,.65)}
.devtest-join-dialog::backdrop{background:rgba(0,0,0,.72)}
.devtest-join-dialog h3{font-size:22px;margin:0 0 14px}
.devtest-join-dialog p{color:#b1c8d9;line-height:1.6;overflow-wrap:anywhere}
.devtest-join-dialog .devtest-card-actions{justify-content:flex-end}
.devtest-button[disabled]{opacity:.65;cursor:default}
.devtest-empty{padding:28px;text-align:center;border:1px dashed #2b5572;border-radius:13px;color:#a3bbd0}
.devtest-account{padding:17px 20px;background:#0b263c;border:1px solid #214e72;border-radius:12px;margin-bottom:18px}
.devtest-account strong{color:#d8f1ff}
@media(max-width:700px){
  .devtest-hero h1{font-size:33px}
  .devtest-form-grid,.devtest-form-grid.media{grid-template-columns:1fr;gap:0}
  .devtest-cover{float:none;display:block;max-width:100%;width:100%;max-height:220px;margin:0 0 14px}
  .devtest-card .devtest-play-qr{margin:0 0 10px 12px}
  .devtest-tab .devtest-count{font-size:14px}
  .devtest-card{padding:18px}
  .devtest-actions h2{font-size:22px}
}
</style>

<section class="subhero devtest-hero">
    <div class="container">
        <p class="section-kicker">PRIVATE DEVELOPMENT TEST</p>
        <h1>비공개 개발테스트</h1>
        <p>STIMPACK GAME LAB 자체 테스트 프로젝트를 등록하고, 모집부터 완료까지 관리합니다.</p>
    </div>
</section>

<section class="section">
<div class="container">
    <div class="notice-box devtest-intro">
        <b>STIMPACK GAME LAB 개발테스트 센터</b>
        <p>구글 로그인 사용자만 앱을 등록하고 관리할 수 있습니다. 모집 중인 앱은 소유자가 테스트 중으로 옮기고, 테스트를 마친 뒤 완료 처리할 수 있습니다.</p>
        <p class="devtest-note">Google Play의 테스트 참여·설치·평점·리뷰 및 보상과는 연동하지 않는 홈페이지 자체 관리 기능입니다.</p>
    </div>

    <nav class="simple-card-grid three devtest-tabs" aria-label="개발테스트 단계">
        <?php foreach ([
            'recruiting' => ['👥', '모집 중', '테스터 모집 프로젝트'],
            'testing' => ['🧪', '테스트 중', '진행 중인 테스트 프로젝트'],
            'completed' => ['🏁', '완료', '완료된 프로젝트 기록'],
        ] as $key => $item): ?>
            <a class="simple-card devtest-tab<?= $tab === $key ? ' is-selected' : '' ?>"
               href="/test/?tab=<?= e($key) ?>#devtest-projects"
               <?= $tab === $key ? 'aria-current="page"' : '' ?>>
                <span class="simple-icon" aria-hidden="true"><?= e($item[0]) ?></span>
                <div><b><?= e($item[1]) ?></b><p><?= e($item[2]) ?></p></div>
                <span class="devtest-count"><?= number_format($counts[$key]) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($viewer === null): ?>
        <div class="notice-box">
            <b>Google 로그인 후 이용할 수 있습니다.</b>
            <p>앱 등록, 프로젝트 조회, 모집 인원 수정과 상태 변경은 구글 인증 사용자에게만 제공됩니다.</p>
            <a class="devtest-button primary" href="/auth/google_login.php?return=%2Ftest%2F">Google 로그인</a>
        </div>
    <?php elseif (!$databaseReady): ?>
        <div class="devtest-alert" role="alert">
            개발테스트 DB가 준비되지 않았습니다. 관리자는 sql/20261127_devtest_projects.sql, sql/20261127_devtest_media.sql, sql/20261127_devtest_applications.sql, sql/20261127_devtest_google_play.sql 적용 여부를 확인해 주세요.
        </div>
    <?php else: ?>
        <div id="devtest-projects"></div>
        <?php if ($noticeText !== ''): ?><div class="devtest-success" role="status"><?= e($noticeText) ?></div><?php endif; ?>
        <?php if ($errorText !== ''): ?><div class="devtest-alert" role="alert"><?= e($errorText) ?></div><?php endif; ?>

        <?php if ($active !== null): ?>
            <div class="devtest-account">
                <strong>내 진행 중인 앱: <?= e((string)$active['title']) ?></strong>
                <div class="devtest-note">현재 상태: <?= e($tabNames[(string)$active['status']] ?? '') ?> · 이 앱이 완료 처리되면 새 앱을 등록할 수 있습니다.</div>
            </div>
        <?php endif; ?>

        <div class="devtest-actions">
            <h2><?= e($tabNames[$tab]) ?> <small style="font-size:15px;color:#80accb;">(<?= number_format($counts[$tab]) ?>)</small></h2>
            <?php if ($tab === 'recruiting' && $active === null && $formMode !== 'create'): ?>
                <a class="devtest-button primary" href="/test/?tab=recruiting&new=1#project-form">+ 앱 등록</a>
            <?php endif; ?>
        </div>
        <?php if ($tab === 'recruiting'): ?>
            <p class="devtest-note">현재 모집 인원은 앱 등록자가 입력하고 관리하는 숫자입니다. 실제 계정 참여 자동 집계 기능은 아직 포함하지 않았습니다.</p>
        <?php endif; ?>

        <?php if ($formMode !== ''): ?>
            <div class="devtest-form" id="project-form">
                <h3><?= $formMode === 'edit' ? '앱 정보 수정' : '모집할 앱 등록' ?></h3>
                <form method="post" enctype="multipart/form-data" action="/test/?tab=<?= e($tab) ?>#project-form">
                    <input type="hidden" name="csrf" value="<?= e(gamelab_csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= e($formMode) ?>">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <?php if ($formMode === 'edit'): ?>
                        <input type="hidden" name="project_id" value="<?= $formProjectId ?>">
                    <?php endif; ?>
                    <div class="devtest-field">
                        <label for="devtest-title">앱 이름</label>
                        <input id="devtest-title" name="title" type="text" required minlength="2" maxlength="100" value="<?= e((string)$formValues['title']) ?>" placeholder="예: 내 진료수첩">
                    </div>
                    <div class="devtest-field">
                        <label for="devtest-description">앱 설명 및 테스트 목적</label>
                        <textarea id="devtest-description" name="description" required minlength="10" maxlength="2500" rows="5" placeholder="앱의 기능과 어떤 점을 테스트하고 싶은지 적어 주세요."><?= e((string)$formValues['description']) ?></textarea>
                    </div>
                    <div class="devtest-form-grid">
                        <div class="devtest-field">
                            <label for="devtest-recruited">현재 모집 완료 인원</label>
                            <input id="devtest-recruited" name="recruited_count" type="number" min="0" max="100000" required value="<?= e((string)$formValues['recruited_count']) ?>">
                        </div>
                        <div class="devtest-field">
                            <label for="devtest-target">목표 모집 인원</label>
                            <input id="devtest-target" name="target_count" type="number" min="1" max="100000" required value="<?= e((string)$formValues['target_count']) ?>">
                        </div>
                        <div class="devtest-field">
                            <label for="devtest-contact">테스터 신청 알림 이메일 (Google 인증)</label>
                            <input id="devtest-contact" name="contact_email" type="email" required readonly maxlength="254" value="<?= e((string)$formValues['contact_email']) ?>">
                            <small>앱 등록 때 인증된 구글 이메일로 참여 신청을 전달합니다. 임의로 변경할 수 없습니다.</small>
                        </div>
                    </div>
                    <div class="devtest-form-grid media">
                        <div class="devtest-field">
                            <label for="devtest-image">대표 이미지 (1장)</label>
                            <input id="devtest-image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
                            <small>JPG · PNG · WEBP, 최대 5MB. 새 이미지 선택 시 기존 이미지를 교체합니다.</small>
                            <?php if ($formMode === 'edit' && $formValues['image_path'] !== ''): ?>
                                <img class="devtest-image-preview" src="<?= e((string)$formValues['image_path']) ?>" alt="등록된 대표 이미지">
                                <label><input type="checkbox" name="remove_image" value="1"> 기존 이미지 삭제</label>
                            <?php endif; ?>
                        </div>
                        <div class="devtest-field">
                            <label for="devtest-video">소개 영상 링크 (선택)</label>
                            <input id="devtest-video" name="video_url" type="url" maxlength="500" value="<?= e((string)$formValues['video_url']) ?>" placeholder="https://www.youtube.com/... 또는 https://www.tiktok.com/...">
                            <small>유튜브 · 쇼츠 · 틱톡 영상의 공유 링크를 입력해 주세요.</small>
                        </div>
                    </div>
                    <div class="devtest-field">
                        <label for="devtest-play">Google Play 링크 (선택)</label>
                        <input id="devtest-play" name="play_url" type="url" maxlength="500" value="<?= e((string)$formValues['play_url']) ?>" placeholder="https://play.google.com/store/apps/details?id=...">
                        <small>앱의 Google Play 주소나 공식 테스터 참여 링크를 등록하세요. 테스터 등록·승인은 앱 소유자가 직접 처리합니다.</small>
                    </div>
                    <div class="devtest-card-actions">
                        <button class="devtest-button primary" type="submit"><?= $formMode === 'edit' ? '수정 저장' : '모집 중에 등록' ?></button>
                        <a class="devtest-button muted" href="/test/?tab=<?= e($tab) ?>#devtest-projects">취소</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!$projects): ?>
            <div class="devtest-empty">현재 <?= e($tabNames[$tab]) ?> 앱이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($projects as $project): ?>
                <article class="devtest-card<?= !empty($project['play_url']) ? ' has-play-qr' : '' ?>" id="devtest-project-<?= (int)$project['id'] ?>">
                    <?php if (!empty($project['image_path'])): ?>
                        <img class="devtest-cover" src="<?= e((string)$project['image_path']) ?>" alt="<?= e((string)$project['title']) ?> 대표 이미지" loading="lazy">
                    <?php endif; ?>
                    <?php if (!empty($project['play_url'])): ?>
                        <a class="devtest-play-qr"
                           href="<?= e((string)$project['play_url']) ?>"
                           target="_blank" rel="noopener noreferrer"
                           title="Google Play QR" aria-label="<?= e((string)$project['title']) ?> Google Play QR">
                            <span class="devtest-play-qr-code" data-qr-url="<?= e((string)$project['play_url']) ?>"
                                  role="img" aria-label="Google Play 주소 QR 코드"></span>
                        </a>
                    <?php endif; ?>
                    <h3><?= e((string)$project['title']) ?></h3>
                    <div class="devtest-meta">
                        <span>현재 모집 <?= number_format((int)$project['recruited_count']) ?>명 / 목표 <?= number_format((int)$project['target_count']) ?>명</span>
                        <span>등록일 <?= e(substr((string)$project['created_at'], 0, 10)) ?></span>
                        <?php if ($project['started_at'] !== null): ?>
                            <span>테스트 시작 <?= e(substr((string)$project['started_at'], 0, 10)) ?></span>
                        <?php endif; ?>
                        <?php if ($project['completed_at'] !== null): ?>
                            <span>완료일 <?= e(substr((string)$project['completed_at'], 0, 10)) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="devtest-description" id="devtest-description-<?= (int)$project['id'] ?>"><?= e((string)$project['description']) ?></p>
                    <div class="devtest-card-toolbar" role="group" aria-label="<?= e((string)$project['title']) ?> 메뉴">
                        <button class="devtest-button muted" type="button"
                                data-toggle-description="devtest-description-<?= (int)$project['id'] ?>"
                                aria-expanded="false"
                                aria-controls="devtest-description-<?= (int)$project['id'] ?>">자세히 보기</button>
                        <?php if (!empty($project['play_url'])): ?>
                            <a class="devtest-button muted" href="<?= e((string)$project['play_url']) ?>"
                               target="_blank" rel="noopener noreferrer">Google Play</a>
                        <?php endif; ?>
                        <?php if (!empty($project['video_url'])): ?>
                            <a class="devtest-button muted" href="<?= e((string)$project['video_url']) ?>"
                               target="_blank" rel="noopener noreferrer">소개 영상</a>
                        <?php endif; ?>
                        <?php if ($project['status'] === 'recruiting' && !hash_equals((string)$project['owner_google_sub'], $viewer['sub'])): ?>
                            <?php if (isset($joinedProjectIds[(int)$project['id']])): ?>
                                <?php if ($joinedProjectIds[(int)$project['id']] === 'owner_confirmed'): ?>
                                    <button class="devtest-button complete" type="button" disabled>오너 확인</button>
                                <?php else: ?>
                                    <button class="devtest-button muted" type="button" disabled>신청 완료</button>
                                <?php endif; ?>
                            <?php elseif ((int)$project['recruited_count'] >= (int)$project['target_count']): ?>
                                <button class="devtest-button muted" type="button" disabled>모집 마감</button>
                            <?php else: ?>
                                <button class="devtest-button primary" type="button" data-join-dialog="devtest-join-<?= (int)$project['id'] ?>">테스트 참여</button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (hash_equals((string)$project['owner_google_sub'], $viewer['sub']) && $project['status'] !== 'completed'): ?>
                            <a class="devtest-button muted"
                               href="/test/?tab=<?= e($tab) ?>&edit=<?= (int)$project['id'] ?>#project-form">정보·모집 인원 수정</a>
                            <?php if ($project['status'] === 'recruiting'): ?>
                                <form method="post" action="/test/">
                                    <input type="hidden" name="csrf" value="<?= e(gamelab_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="start">
                                    <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                                    <button class="devtest-button primary" type="submit">테스트 진행</button>
                                </form>
                            <?php elseif ($project['status'] === 'testing'): ?>
                                <form method="post" action="/test/" onsubmit="return confirm('테스트를 완료 처리할까요? 완료 후에는 수정할 수 없습니다.');">
                                    <input type="hidden" name="csrf" value="<?= e(gamelab_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                                    <button class="devtest-button complete" type="submit">완료</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($project['status'] === 'recruiting'
                        && !hash_equals((string)$project['owner_google_sub'], $viewer['sub'])
                        && !isset($joinedProjectIds[(int)$project['id']])
                        && (int)$project['recruited_count'] < (int)$project['target_count']): ?>
                        <dialog class="devtest-join-dialog" id="devtest-join-<?= (int)$project['id'] ?>">
                            <h3>테스트 참여 신청</h3>
                            <p><strong><?= e((string)$project['title']) ?></strong>에 테스트 참여 의사를 전달합니다.</p>
                            <form method="post" action="/test/">
                                <input type="hidden" name="csrf" value="<?= e(gamelab_csrf_token()) ?>">
                                <input type="hidden" name="action" value="join">
                                <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                                <div class="devtest-field">
                                    <label for="applicant-email-<?= (int)$project['id'] ?>">테스트에 참여할 이메일</label>
                                    <input id="applicant-email-<?= (int)$project['id'] ?>" name="applicant_email" type="email" required maxlength="254" autocomplete="email" value="<?= e($viewer['email']) ?>" placeholder="you@example.com">
                                    <small>이메일은 앱 등록자에게 전달됩니다. 입력한 이메일은 별도로 인증하지 않습니다.</small>
                                </div>
                                <p class="devtest-note">이 신청은 홈페이지 내 참여 의사 전달이며 Google Play 테스트 등록은 별도로 진행됩니다.</p>
                                <div class="devtest-card-actions">
                                    <button class="devtest-button muted" type="button" data-close-dialog>취소</button>
                                    <button class="devtest-button primary" type="submit">참여 신청 전송</button>
                                </div>
                            </form>
                        </dialog>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
</section>
<script src="/assets/js/vendor/qrcode.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof QRCode !== 'function') return;
    document.querySelectorAll('.devtest-play-qr-code[data-qr-url]').forEach(function (target) {
        if (target.dataset.qrRendered) return;
        try {
            new QRCode(target, {
                text: target.dataset.qrUrl,
                width: 80,
                height: 80,
                correctLevel: QRCode.CorrectLevel.M
            });
            target.dataset.qrRendered = '1';
        } catch (error) {
            // Keep the Google Play link clickable even when QR generation fails.
            console.error('Devtest Play QR generation failed', error);
        }
    });
});
document.addEventListener('click', function (event) {
    const detailButton = event.target.closest('[data-toggle-description]');
    if (detailButton) {
        const description = document.getElementById(detailButton.getAttribute('data-toggle-description'));
        if (description) {
            const expanded = detailButton.getAttribute('aria-expanded') === 'true';
            description.classList.toggle('is-expanded', !expanded);
            detailButton.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            detailButton.textContent = expanded ? '자세히 보기' : '접기';
        }
        return;
    }
    const open = event.target.closest('[data-join-dialog]');
    if (open) {
        const dialog = document.getElementById(open.getAttribute('data-join-dialog'));
        if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
        return;
    }
    const close = event.target.closest('[data-close-dialog]');
    if (close) {
        const dialog = close.closest('dialog');
        if (dialog) dialog.close();
    }
});
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
