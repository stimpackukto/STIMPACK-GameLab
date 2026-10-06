<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
reddit_require_admin();
$store = reddit_read_token_store();
$configured = reddit_is_configured();
$connected = !empty($store['refresh_token']);
?><!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>STIMPACK Reddit 연결</title>
<style>body{font-family:system-ui;background:#08101d;color:#e7eef8;margin:0;padding:30px}.box{max-width:760px;margin:auto;background:#0e1630;border:1px solid #263552;border-radius:16px;padding:24px}.ok{color:#70e59b}.bad{color:#ff9c9c}a.btn{display:inline-block;margin-top:12px;padding:10px 14px;border-radius:10px;background:#ff4500;color:#fff;text-decoration:none;font-weight:800}code{color:#ffd36a}</style></head><body><div class="box">
<h1>STIMPACK 공통 Reddit 인증</h1>
<p>클라이언트 설정: <b class="<?= $configured ? 'ok':'bad' ?>"><?= $configured ? '완료':'미완료' ?></b></p>
<p>Reddit 연결: <b class="<?= $connected ? 'ok':'bad' ?>"><?= $connected ? '연결됨':'연결 안 됨' ?></b></p>
<?php if ($connected): ?><p>계정: <b>u/<?= htmlspecialchars((string)($store['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></b></p><?php endif; ?>
<p>현재 GAME LAB ACCOUNT_ID: <code><?= reddit_current_account_id() ?></code></p>
<?php if ($configured): ?><a class="btn" href="/reddit/oauth_start.php">Reddit 계정 승인/재승인</a><?php endif; ?>
</div></body></html>
