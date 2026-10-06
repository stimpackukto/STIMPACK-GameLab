<?php
declare(strict_types=1);

require_once dirname(__DIR__)
    . '/includes/config.php';

require_once dirname(__DIR__)
    . '/includes/admin.php';

require_once dirname(__DIR__)
    . '/includes/tester.php';


/* =========================================================
 * POST 전용
 * ========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {

    http_response_code(405);

    exit('잘못된 요청입니다.');
}


/* =========================================================
 * CSRF
 * ========================================================= */

if (
    !gamelab_verify_csrf(
        (string)(
            $_POST['csrf']
            ?? ''
        )
    )
) {

    http_response_code(403);

    exit('잘못된 요청입니다.');
}


/* =========================================================
 * Google 로그인 정보
 * ========================================================= */

$email = trim(
    (string)(
        $_SESSION['google_user']['email']
        ?? $_SESSION['google_email']
        ?? ''
    )
);


$googleSub = trim(
    (string)(
        $_SESSION['google_user']['sub']
        ?? ''
    )
);


$email =
    gamelab_normalize_email(
        $email
    );


if ($email === '') {

    header(
        'Location: /auth/google_login.php'
    );

    exit;
}


/* =========================================================
 * 이미 테스터 DB에 존재하는지 확인
 *
 * pending / approved / rejected
 * 어떤 상태라도 중복 신청 불가
 * ========================================================= */

$currentStatus =
    gamelab_tester_status(
        $email
    );


if ($currentStatus !== null) {

    header(
        'Location: /apps/?tester='
        . rawurlencode(
            $currentStatus
        )
    );

    exit;
}


/* =========================================================
 * WOW auth.account 확인
 * ========================================================= */

if (
    !gamelab_is_wow_account_email(
        $email
    )
) {

    http_response_code(403);

    exit(
        'WOW 계정에 등록된 이메일과 '
        . 'Google 로그인 이메일이 일치하지 않습니다.'
    );
}


/* =========================================================
 * 신청 등록
 *
 * status = pending
 * ========================================================= */

$created =
    gamelab_create_tester_request(
        $email,
        $googleSub
    );


if (!$created) {

    header(
        'Location: /apps/'
    );

    exit;
}


/* =========================================================
 * 관리자 메일
 *
 * DB 기록이 우선.
 * 메일 실패해도 신청 기록은 유지.
 * ========================================================= */

$adminEmail =
    'esotarsound@gmail.com';


$subjectText =
    '[STIMPACK GAME LAB] 앱 테스터 참여 신청';


$subject =
    function_exists(
        'mb_encode_mimeheader'
    )
        ? mb_encode_mimeheader(
            $subjectText,
            'UTF-8',
            'B',
            "\r\n"
        )
        : $subjectText;


$message =
    "STIMPACK GAME LAB 앱 테스터 참여 신청\n\n"
    . "신청 이메일: "
    . $email
    . "\n"
    . "상태: 승인 대기\n"
    . "신청 시간: "
    . date('Y-m-d H:i:s')
    . "\n";


$headers = [

    'MIME-Version: 1.0',

    'Content-Type: text/plain; charset=UTF-8',

    'From: STIMPACK GAME LAB <noreply@stimpack.pe.kr>',
];


$mailSent = @mail(
    $adminEmail,
    $subject,
    $message,
    implode(
        "\r\n",
        $headers
    )
);


if (!$mailSent) {

    error_log(
        '[GAME LAB TESTER] '
        . '관리자 메일 발송 실패: '
        . $email
    );
}


/* =========================================================
 * 앱 페이지
 * ========================================================= */

header(
    'Location: /apps/?tester=pending'
);

exit;