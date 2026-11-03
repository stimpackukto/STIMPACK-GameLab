<?php
declare(strict_types=1);

/**
 * One-time CLI registration for BesideSNS on the existing GameLab app catalog.
 * Run on the actual website, after a backup, as the catalog file owner.
 * Does not change any other app, WoW, or existing GitHub-ignored JSON records.
 * No Android APK is created here; add a signed beta APK separately.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/catalog.php';

$catalogPath = catalog_file('app');
if (!is_file($catalogPath)) {
    fwrite(STDERR, "App catalog missing; refusing to create an empty replacement.\n");
    exit(1);
}

$raw = file_get_contents($catalogPath);
$items = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($items) || !array_is_list($items)) {
    fwrite(STDERR, "App catalog is invalid; existing app records left untouched.\n");
    exit(1);
}

foreach ($items as $item) {
    if ((string)($item['id'] ?? '') === 'pe.kr.forum.beside') {
        echo "BesideSNS already registered; existing entry left untouched.\n";
        exit(0);
    }
}

catalog_add('app', [
    'id' => 'pe.kr.forum.beside',
    'title' => '곁에 있습니다 (BesideSNS)',
    'purpose' => '누군가에게 쉽게 꺼내지 못하는 이야기를, 자신의 속도로 기록할 수 있는 공간을 만들고 싶었습니다. 혼자만의 기록을 지키면서도 원할 때는 이야기를 나누고 조용한 공감을 받을 수 있도록 개발하고 있습니다.',
    'description' => "누구에게도 쉽게 말하지 못했던 마음을 나만의 여정에 기록하는 공간입니다.\n\n■ 혼자만의 여정: 로그인 없이 기록을 시작하고 기기에 비공개로 보관할 수 있습니다.\n■ 공개 여정: 작성자가 직접 선택한 이야기만 다른 사람에게 공개됩니다.\n■ 조용한 공감: ♥ 곁에 있어요로 여정을 지켜보고, ✨ 응원해요로 마음을 전할 수 있습니다.\n■ 새 기록 알림: 관심을 표시한 공개 여정의 새 기록을 알림으로 받을 수 있습니다.\n■ 여정 마무리: 여정을 마친 후 댓글로 서로 응원할 수 있습니다.\n\n현재 Android 공개 베타 테스트 중입니다. 기능과 화면은 개선 과정에서 바뀔 수 있으며, 비공개 기록은 앱 삭제 시 사라질 수 있습니다. 민감한 개인정보를 공개하지 않도록 주의해 주세요.",
    'image' => '/assets/images/apps/default.webp',
    'status' => '테스트중',
    'released' => false,
    'released_at' => '',
    'url' => '', // No Play Store listing exists yet.
    'web_url' => '',
    'download_url' => '/app/beside/releases/beside-beta.apk',
    'action' => '자세히 보기',
    'external' => false,
    'created_at' => date('c'),
]);

echo "BesideSNS registered as 테스트중.\n";
echo "APK button appears only after /var/www/gamelab/app/beside/releases/beside-beta.apk exists.\n";
