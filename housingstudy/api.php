<?php
declare(strict_types=1);

/**
 * JSON API for HousingStudy Android Credential Manager sign-in and native forum.
 * Uses the existing Google web OAuth client ID for ID-token audience validation.
 * Google ID tokens are verified ONLINE by Google's tokeninfo endpoint, with an
 * expiring one-time nonce. Only a SHA-256 hash of our OWN session token is stored.
 */
require_once __DIR__ . '/common.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function api_out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function api_fail(int $status, string $message): never {
    api_out(['ok'=>false, 'error'=>$message], $status);
}
function api_input(): array {
    $type = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_starts_with(strtolower($type), 'application/json')) {
        $text = file_get_contents('php://input', false, null, 0, 65537);
        if ($text === false || strlen($text) > 65536) api_fail(413, '요청이 너무 커요.');
        $data = json_decode($text, true);
        if (!is_array($data)) api_fail(400, '잘못된 요청입니다.');
        return $data;
    }
    return $_POST;
}
function api_field(array $data, string $name, int $limit = 10000): string {
    $value = $data[$name] ?? '';
    if (!is_string($value)) api_fail(400, '값의 형식이 올바르지 않습니다.');
    $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > $limit) api_fail(400, '입력 글자 수를 초과했어요.');
    return $value;
}
function api_bearer(array $data): string {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $m)) return $m[1];
    // POST-body fallback if the reverse proxy omits Authorization.
    $token = $data['session_token'] ?? '';
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : '';
}
function api_auth(PDO $db, array $data, bool $required = true): ?array {
    $token = api_bearer($data);
    if ($token === '') {
        if ($required) api_fail(401, '구글 로그인이 필요해요.');
        return null;
    }
    $query = $db->prepare('SELECT author_hash,author_name FROM housingstudy_app_sessions WHERE token_hash=? AND expires_at>NOW() LIMIT 1');
    $query->execute([hash('sha256', $token)]);
    $user = $query->fetch();
    if (!$user && $required) api_fail(401, '로그인이 만료됐어요. 다시 로그인해 주세요.');
    return $user ?: null;
}
function api_verify_google(string $token, string $expectedAudience, string $nonce): array {
    if (strlen($token) > 10000 || substr_count($token, '.') !== 2) api_fail(401, '잘못된 Google 인증값입니다.');
    if (!function_exists('curl_init')) api_fail(503, '서버 인증 기능이 준비되지 않았어요.');
    // The token is sent server-to-server over TLS, never in the app's URL.
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($token);
    $curl = curl_init($url);
    if ($curl === false) api_fail(503, 'Google 인증 서버에 연결할 수 없어요.');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($body) || $http !== 200) api_fail(401, 'Google 인증을 확인할 수 없어요.');
    $claims = json_decode($body, true);
    if (!is_array($claims)) api_fail(401, 'Google 인증 응답이 잘못됐어요.');
    if (!hash_equals($expectedAudience, (string)($claims['aud'] ?? ''))) api_fail(401, '다른 앱의 인증 정보입니다.');
    if (!in_array((string)($claims['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)) api_fail(401, 'Google 발급자가 일치하지 않습니다.');
    if (!ctype_digit((string)($claims['exp'] ?? '')) || (int)$claims['exp'] <= time()) api_fail(401, 'Google 인증이 만료됐어요.');
    if ((string)($claims['nonce'] ?? '') !== $nonce) api_fail(401, '인증 보안값이 일치하지 않습니다.');
    $sub = (string)($claims['sub'] ?? '');
    if ($sub === '' || strlen($sub) > 255) api_fail(401, 'Google 사용자 정보를 확인할 수 없어요.');
    return $claims;
}
function api_post_info(array $post): array {
    return [
        'id'=>(int)$post['id'], 'category'=>(string)$post['category'],
        'title'=>(string)$post['title'], 'body'=>(string)($post['body'] ?? ''),
        'author'=>(string)$post['author_name'], 'date'=>(string)$post['created_at'],
        'resource_url'=>(string)($post['resource_url'] ?? ''),
        'attachment_name'=>(string)($post['attachment_name'] ?? ''),
        'has_attachment'=>!empty($post['attachment_token']),
        'author_hash'=>(string)$post['author_hash'],
    ];
}
try {
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $data = $method === 'POST' ? api_input() : [];
    $action = (string)($_GET['action'] ?? $data['action'] ?? '');
    $db = hs_db();

    if ($action === 'nonce' && $method === 'GET') {
        // Opportunistic cleanup; tokens and one-time challenges are short-lived.
        if (random_int(1, 20) === 1) {
            $db->exec('DELETE FROM housingstudy_login_nonces WHERE expires_at < NOW()');
            $db->exec('DELETE FROM housingstudy_app_sessions WHERE expires_at < NOW()');
        }
        $config = require '/etc/stimpack/google_oauth.php';
        $client = trim((string)($config['client_id'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.apps\.googleusercontent\.com$/D', $client)) api_fail(503, 'Google 인증 설정이 준비되지 않았어요.');
        $nonce = bin2hex(random_bytes(24));
        $db->prepare('INSERT INTO housingstudy_login_nonces (nonce_hash, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))')
            ->execute([hash('sha256', $nonce)]);
        api_out(['ok'=>true,'web_client_id'=>$client,'nonce'=>$nonce]);
    }
    if ($action === 'login' && $method === 'POST') {
        $idToken = api_field($data, 'id_token', 10000);
        $nonce = api_field($data, 'nonce', 100);
        if ($idToken === '' || !preg_match('/^[a-f0-9]{48}$/D', $nonce)) api_fail(400, '로그인 요청 정보가 부족해요.');
        $config = require '/etc/stimpack/google_oauth.php';
        $audience = trim((string)($config['client_id'] ?? ''));
        if ($audience === '') api_fail(503, '인증 설정을 확인해 주세요.');
        $claims = api_verify_google($idToken, $audience, $nonce);
        // Consume nonce atomically; replay of the same ID token is rejected.
        $stmt = $db->prepare('DELETE FROM housingstudy_login_nonces WHERE nonce_hash=? AND expires_at > NOW()');
        $stmt->execute([hash('sha256', $nonce)]);
        if ($stmt->rowCount() !== 1) api_fail(401, '이미 사용했거나 만료된 인증 요청이에요.');
        $hash = hash('sha256', (string)$claims['sub']);
        $name = '학습자-' . strtoupper(substr($hash, 0, 6));
        $session = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO housingstudy_app_sessions (token_hash,author_hash,author_name,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 14 DAY))')
            ->execute([hash('sha256', $session), $hash, $name]);
        api_out(['ok'=>true,'session_token'=>$session,'author'=>$name,'expires_in'=>1209600]);
    }
    if ($action === 'list' && $method === 'GET') {
        $page = min(500, max(1, (int)($_GET['page'] ?? 1)));
        $category = trim((string)($_GET['category'] ?? '전체'));
        if ($category !== '전체' && !in_array($category, hs_categories(), true)) api_fail(400,'분류가 올바르지 않아요.');
        $sql = 'SELECT p.id,p.author_hash,p.author_name,p.category,p.title,p.created_at,
          (SELECT COUNT(*) FROM housingstudy_comments c WHERE c.post_id=p.id AND c.deleted_at IS NULL) AS comments
          FROM housingstudy_posts p WHERE p.deleted_at IS NULL';
        $params = [];
        if ($category !== '전체') { $sql .= ' AND p.category=?'; $params[] = $category; }
        $sql .= ' ORDER BY p.id DESC LIMIT 20 OFFSET ' . (int)(($page-1)*20);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $p) {
            $rows[] = ['id'=>(int)$p['id'],'category'=>$p['category'],'title'=>$p['title'],
                       'author'=>$p['author_name'],'author_hash'=>$p['author_hash'],
                       'date'=>$p['created_at'],'comments'=>(int)$p['comments']];
        }
        api_out(['ok'=>true,'posts'=>$rows,'page'=>$page]);
    }
    if ($action === 'post' && $method === 'GET') {
        $post = hs_post($db, (int)($_GET['id'] ?? 0));
        if (!$post) api_fail(404,'게시글을 찾지 못했어요.');
        $stmt = $db->prepare('SELECT id,author_hash,author_name,body,created_at FROM housingstudy_comments WHERE post_id=? AND deleted_at IS NULL ORDER BY id ASC LIMIT 200');
        $stmt->execute([$post['id']]);
        $comments = [];
        foreach ($stmt->fetchAll() as $c) $comments[] = [
            'id'=>(int)$c['id'], 'author'=>(string)$c['author_name'], 'author_hash'=>(string)$c['author_hash'],
            'body'=>(string)$c['body'], 'date'=>(string)$c['created_at']];
        api_out(['ok'=>true,'post'=>api_post_info($post),'comments'=>$comments]);
    }
    if ($action === 'session' && $method === 'POST') {
        $user = api_auth($db, $data);
        api_out(['ok'=>true,'author'=>$user['author_name']]);
    }
    if ($action === 'logout' && $method === 'POST') {
        $token = api_bearer($data);
        if ($token !== '') $db->prepare('DELETE FROM housingstudy_app_sessions WHERE token_hash=?')->execute([hash('sha256', $token)]);
        api_out(['ok'=>true]);
    }
    if ($method !== 'POST') api_fail(405,'지원하지 않는 요청입니다.');
    $user = api_auth($db, $data);

    if ($action === 'create') {
        $title = api_field($data, 'title', 160);
        $body = api_field($data, 'body', 10000);
        $category = api_field($data, 'category', 30);
        if (mb_strlen($title) < 3 || mb_strlen($body) < 5 || !in_array($category, hs_categories(), true)) api_fail(400,'제목·내용·분류를 확인해주세요.');
        $url = hs_clean_url(api_field($data, 'resource_url', 2000));
        hs_rate_limit($db,'housingstudy_posts',$user['author_hash'],30);
        $file = hs_upload();
        try {
            $stmt = $db->prepare('INSERT INTO housingstudy_posts
              (author_hash,author_name,category,title,body,resource_url,attachment_token,attachment_name,attachment_mime)
              VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$user['author_hash'],$user['author_name'],$category,$title,$body,$url,
                $file['token'] ?? null,$file['name'] ?? null,$file['mime'] ?? null]);
        } catch (Throwable $error) {
            if ($file) @unlink($file['disk']);
            throw $error;
        }
        api_out(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
    }
    if ($action === 'comment') {
        $id=(int)($data['post_id'] ?? 0);
        if (!hs_post($db,$id)) api_fail(404,'게시글이 없습니다.');
        $body=api_field($data,'body',2000);
        if (mb_strlen($body)<2) api_fail(400,'댓글을 2자 이상 입력하세요.');
        hs_rate_limit($db,'housingstudy_comments',$user['author_hash'],10);
        $db->prepare('INSERT INTO housingstudy_comments(post_id,author_hash,author_name,body) VALUES (?,?,?,?)')
            ->execute([$id,$user['author_hash'],$user['author_name'],$body]);
        api_out(['ok'=>true]);
    }
    if ($action === 'delete') {
        $id=(int)($data['post_id'] ?? 0);
        $post=hs_post($db,$id);
        if (!$post) api_fail(404,'게시글이 없습니다.');
        if (!hash_equals((string)$post['author_hash'],(string)$user['author_hash'])) api_fail(403,'작성자만 삭제할 수 있어요.');
        $db->prepare('UPDATE housingstudy_posts SET deleted_at=NOW() WHERE id=?')->execute([$id]);
        if ($post['attachment_token']) {
            foreach (['.pdf','.jpg','.png'] as $ext) @unlink(HS_UPLOAD_DIR.'/'.$post['attachment_token'].$ext);
        }
        api_out(['ok'=>true]);
    }
    if ($action === 'delete_comment') {
        $id=(int)($data['comment_id'] ?? 0);
        $stmt=$db->prepare('UPDATE housingstudy_comments SET deleted_at=NOW() WHERE id=? AND author_hash=? AND deleted_at IS NULL');
        $stmt->execute([$id,$user['author_hash']]);
        api_out(['ok'=>$stmt->rowCount()>0]);
    }
    if ($action === 'report') {
        $postId=(int)($data['post_id'] ?? 0);
        if (!hs_post($db,$postId)) api_fail(404,'글이 존재하지 않아요.');
        $reason=api_field($data,'reason',240);
        if (!in_array($reason,['스팸·광고','욕설·혐오','개인정보 노출','저작권 침해','기타'],true)) api_fail(400,'신고 사유를 선택하세요.');
        $db->prepare('INSERT IGNORE INTO housingstudy_reports(post_id,reporter_hash,reason) VALUES (?,?,?)')
            ->execute([$postId,$user['author_hash'],$reason]);
        api_out(['ok'=>true]);
    }
    if ($action === 'block') {
        $post=hs_post($db,(int)($data['post_id'] ?? 0));
        if (!$post) api_fail(404,'게시글이 없습니다.');
        if ($user['author_hash']===$post['author_hash']) api_fail(400,'자신을 차단할 수 없어요.');
        $db->prepare('INSERT IGNORE INTO housingstudy_blocks(blocker_hash,blocked_hash) VALUES (?,?)')
            ->execute([$user['author_hash'],$post['author_hash']]);
        api_out(['ok'=>true,'blocked_hash'=>$post['author_hash']]);
    }
    api_fail(400,'지원하지 않는 요청입니다.');
} catch (PDOException $error) {
    error_log('HousingStudy native API DB error: '.$error->getCode());
    api_fail(503,'게시판 데이터베이스 점검이 필요해요.');
} catch (Throwable $error) {
    error_log('HousingStudy native API: '.$error->getMessage());
    api_fail(500,'서버에서 요청을 처리하지 못했어요.');
}
