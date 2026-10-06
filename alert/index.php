<?php
header("Content-Type: text/plain; charset=UTF-8");

// 🔥 DB 연결
$conn = new mysqli("127.0.0.1", "esotar", "@!sys740115", "appdata");
if ($conn->connect_error) {
    echo "SERVERALERT:\nDB ERROR";
    exit;
}

// =========================
// 🔥 디스코드 정보 가져오기
// =========================
$json = @file_get_contents("https://discord.com/api/guilds/1088342432690749440/widget.json");
$data = $json ? json_decode($json, true) : null;

$members = $data['members'] ?? [];
$online = 0;
$idle   = 0;
$dnd    = 0;

foreach ($members as $m) {
    switch ($m['status']) {
        case 'online':
            $online++;
            break;
        case 'idle':
            $idle++;
            break;
        case 'dnd':
            $dnd++;
            break;
    }
}

// 총 인원 (위젯 기준 = 접속자 수)
$total = count($members);

// =========================
// 🔥 서버 상태 확인 추가
// =========================
$serverHost = "stimpack.pe.kr";
$serverPort = 8085;
$serverTimeout = 1;

$fp = @fsockopen($serverHost, $serverPort, $errno, $errstr, $serverTimeout);
$isServerOnline = ($fp !== false);

if ($fp) {
    fclose($fp);
}

$serverStatusText = $isServerOnline ? "ONLINE" : "OFFLINE";

// =========================
// 🔥 공지 가져오기
// =========================
$sql = "
SELECT title, body, created_at
FROM notice
WHERE visible = 1
ORDER BY pinned DESC, created_at DESC
LIMIT 1
";

$res = $conn->query($sql);
$row = $res ? $res->fetch_assoc() : null;

// 🔥 기본값
$title = $row ? $row['title'] : '공지 없음';
$body  = $row ? $row['body'] : '현재 공지가 없습니다.';
$date  = $row ? $row['created_at'] : '';

// =========================
// 🔥 텍스트 정리
// =========================

// <br> → 줄바꿈
$body = str_replace(["<br>", "<br/>", "<br />"], "\n", $body);

// 태그 제거
$title = strip_tags($title);
$body  = strip_tags($body);

// 엔티티 변환
$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$body  = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// 이모지 제거
function remove_emoji($text) {
    return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
}
$title = remove_emoji($title);
$body  = remove_emoji($body);

// 줄바꿈 통일
$body = str_replace(["\r\n", "\r"], "\n", $body);

// 🔥 여기 교체
$body = preg_replace("/\n{3,}/", "\n\n", $body);

// 앞뒤 공백 제거
$body = trim($body);

// 길이 제한
$body = mb_substr($body, 0, 400);

// =========================
// 🔥 출력
// =========================
echo "SERVERALERT:";

// =========================
// 🔥 상단 상태 영역
// =========================
printf("[DISCORD OnLine] %d 명\n\n",$total,);
printf("[SERVER Status ] %s\n\n",  $serverStatusText);

// =========================
// 🔥 공지 영역
// =========================
$bodyLines = explode("\n", $body);
foreach ($bodyLines as $l) {
    $l = ltrim($l);
    if ($l === '') {
        continue;
    }
    echo "{$l}\n\n";
}
echo "\n{$date}\n";
