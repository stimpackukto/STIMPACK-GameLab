<?php
declare(strict_types=1);


/* =========================================================
 * SESSION 설정
 * ========================================================= */

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();


/* =========================================================
 * Google OAuth 설정
 * ========================================================= */

$config = require '/etc/stimpack/google_oauth.php';


/* =========================================================
 * 오류 페이지
 * ========================================================= */

function fail(string $message): never
{
    http_response_code(403);

    echo '<!doctype html>';
    echo '<html lang="ko">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Google 로그인 실패</title>';
    echo '</head>';

    echo '<body style="
        font-family:
            Pretendard,
            -apple-system,
            BlinkMacSystemFont,
            Segoe UI,
            sans-serif;
        padding:40px;
        background:#071727;
        color:#ffffff;
    ">';

    echo '<div style="
        max-width:620px;
        margin:60px auto;
        padding:30px;
        border:1px solid #234765;
        border-radius:16px;
        background:#0b2033;
    ">';

    echo '<h2 style="margin-top:0;">Google 로그인 실패</h2>';

    echo '<p style="
        color:#c9d8e7;
        line-height:1.7;
    ">';

    echo htmlspecialchars(
        $message,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    echo '</p>';

    echo '<p style="margin-top:25px;">';

    echo '<a
        href="/"
        style="
            display:inline-block;
            padding:11px 17px;
            background:#0ea5e9;
            color:#fff;
            border-radius:9px;
            text-decoration:none;
            font-weight:700;
        "
    >메인으로 돌아가기</a>';

    echo '</p>';

    echo '</div>';
    echo '</body>';
    echo '</html>';

    exit;
}


/* =========================================================
 * CSRF STATE 확인
 * ========================================================= */

$state =
    $_GET['state']
    ?? '';


if (
    !is_string($state)
    ||
    $state === ''
    ||
    !isset(
        $_SESSION['google_oauth_state']
    )
    ||
    !hash_equals(
        (string)$_SESSION['google_oauth_state'],
        $state
    )
) {

    fail(
        '잘못된 로그인 요청입니다.'
    );
}


/*
 * OAuth state는 1회만 사용
 */

unset(
    $_SESSION['google_oauth_state']
);


/* =========================================================
 * Google 인증 오류
 * ========================================================= */

if (
    !empty(
        $_GET['error']
    )
) {

    fail(
        'Google 인증이 취소되었거나 실패했습니다.'
    );
}


/* =========================================================
 * Authorization Code
 * ========================================================= */

$code =
    $_GET['code']
    ?? '';


if (
    !is_string($code)
    ||
    $code === ''
) {

    fail(
        '인증 코드가 없습니다.'
    );
}


/* =========================================================
 * 설정 확인
 * ========================================================= */

$clientId =
    trim(
        (string)(
            $config['client_id']
            ?? ''
        )
    );


$clientSecret =
    trim(
        (string)(
            $config['client_secret']
            ?? ''
        )
    );


$redirectUri =
    trim(
        (string)(
            $config['redirect_uri']
            ?? ''
        )
    );


if (
    $clientId === ''
    ||
    $clientSecret === ''
    ||
    $redirectUri === ''
) {

    fail(
        'Google OAuth 설정이 올바르지 않습니다.'
    );
}


/* =========================================================
 * Authorization Code → Access Token
 * ========================================================= */

$postData = [

    'code' =>
        $code,

    'client_id' =>
        $clientId,

    'client_secret' =>
        $clientSecret,

    'redirect_uri' =>
        $redirectUri,

    'grant_type' =>
        'authorization_code',
];


$ch =
    curl_init(
        'https://oauth2.googleapis.com/token'
    );


if ($ch === false) {

    fail(
        'Google 토큰 요청을 준비하지 못했습니다.'
    );
}


curl_setopt_array(
    $ch,
    [

        CURLOPT_POST =>
            true,

        CURLOPT_POSTFIELDS =>
            http_build_query(
                $postData
            ),

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_TIMEOUT =>
            15,

        CURLOPT_CONNECTTIMEOUT =>
            10,

        CURLOPT_HTTPHEADER =>
            [
                'Content-Type: application/x-www-form-urlencoded',
            ],
    ]
);


$response =
    curl_exec(
        $ch
    );


$httpCode =
    (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );


if (
    $response === false
) {

    curl_close(
        $ch
    );

    fail(
        'Google 토큰 서버 연결에 실패했습니다.'
    );
}


curl_close(
    $ch
);


$token =
    json_decode(
        $response,
        true
    );


if (
    $httpCode !== 200
    ||
    !is_array(
        $token
    )
    ||
    empty(
        $token['access_token']
    )
) {

    fail(
        'Google 토큰 발급에 실패했습니다.'
    );
}


$accessToken =
    (string)$token[
        'access_token'
    ];


/* =========================================================
 * Google UserInfo 가져오기
 * ========================================================= */

$ch =
    curl_init(
        'https://openidconnect.googleapis.com/v1/userinfo'
    );


if ($ch === false) {

    fail(
        'Google 사용자 정보 요청을 준비하지 못했습니다.'
    );
}


curl_setopt_array(
    $ch,
    [

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_TIMEOUT =>
            15,

        CURLOPT_CONNECTTIMEOUT =>
            10,

        CURLOPT_HTTPHEADER =>
            [
                'Authorization: Bearer '
                . $accessToken,
            ],
    ]
);


$response =
    curl_exec(
        $ch
    );


$httpCode =
    (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );


if (
    $response === false
) {

    curl_close(
        $ch
    );

    fail(
        'Google 사용자 정보를 가져오지 못했습니다.'
    );
}


curl_close(
    $ch
);


$user =
    json_decode(
        $response,
        true
    );


if (
    $httpCode !== 200
    ||
    !is_array(
        $user
    )
) {

    fail(
        'Google 사용자 정보 확인에 실패했습니다.'
    );
}


/* =========================================================
 * Google 사용자 정보
 * ========================================================= */

$email =
    strtolower(
        trim(
            (string)(
                $user['email']
                ?? ''
            )
        )
    );


$verified =
    (bool)(
        $user['email_verified']
        ?? false
    );


$googleSub =
    trim(
        (string)(
            $user['sub']
            ?? ''
        )
    );


$name =
    trim(
        (string)(
            $user['name']
            ?? ''
        )
    );


$picture =
    trim(
        (string)(
            $user['picture']
            ?? ''
        )
    );


/* =========================================================
 * Google 사용자 검증
 * ========================================================= */

if ($email === '') {

    fail(
        'Google 이메일 정보를 확인할 수 없습니다.'
    );
}


if (!$verified) {

    fail(
        '확인되지 않은 Google 이메일입니다.'
    );
}


if ($googleSub === '') {

    fail(
        'Google 계정 식별정보를 확인할 수 없습니다.'
    );
}


/* =========================================================
 * 관리자 판정
 *
 * 중요:
 *
 * Google 로그인은 모든 사용자를 허용한다.
 *
 * 단,
 * /etc/stimpack/google_oauth.php 의
 * admin_email 과 일치하는 이메일만
 * 관리자 권한을 갖는다.
 * ========================================================= */

$adminEmail =
    strtolower(
        trim(
            (string)(
                $config['admin_email']
                ?? ''
            )
        )
    );


$isAdmin =
    false;


if (
    $adminEmail !== ''
    &&
    $email !== ''
) {

    $isAdmin =
        hash_equals(
            $adminEmail,
            $email
        );
}


/* =========================================================
 * 로그인 세션 재생성
 * ========================================================= */

session_regenerate_id(
    true
);


/* =========================================================
 * 기존 로그인 세션 정리
 *
 * OAuth 관련 임시값은 제거하되
 * PHP 세션 전체를 지우지는 않는다.
 * ========================================================= */

unset(
    $_SESSION['google_oauth_state']
);


/* =========================================================
 * Google 로그인 사용자 저장
 *
 * 관리자 / 일반 사용자 공통
 * ========================================================= */

$_SESSION['google_user'] = [

    'sub' =>
        $googleSub,

    'email' =>
        $email,

    'name' =>
        $name,

    'picture' =>
        $picture,
];


/* =========================================================
 * 기존 코드 호환
 * ========================================================= */

$_SESSION['google_email'] =
    $email;


/* =========================================================
 * 관리자 권한
 *
 * 관리자:
 * true
 *
 * 일반 Google 로그인 사용자:
 * false
 * ========================================================= */

$_SESSION['gamelab_admin'] =
    $isAdmin;


/* =========================================================
 * 로그인 시간
 * ========================================================= */

$_SESSION['google_login_at'] =
    time();


/* =========================================================
 * 로그인 완료
 * ========================================================= */

header(
    'Location: /'
);

exit;