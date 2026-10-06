<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';


/* =========================================================
 * 이메일 정규화
 * ========================================================= */

function gamelab_normalize_email(
    string $email
): string {

    return strtolower(
        trim($email)
    );
}


/* =========================================================
 * auth.account 이메일 존재 확인
 *
 * WOW 계정에 등록된 이메일인지 확인
 * ========================================================= */

function gamelab_is_wow_account_email(
    string $email
): bool {

    $email =
        gamelab_normalize_email(
            $email
        );


    if ($email === '') {

        return false;
    }


    $pdo =
        gamelab_db();


    $stmt =
        $pdo->prepare(
            '
            SELECT id
            FROM auth.account
            WHERE LOWER(email) = ?
            LIMIT 1
            '
        );


    $stmt->execute([
        $email
    ]);


    return
        $stmt->fetchColumn()
        !== false;
}


/* =========================================================
 * 테스터 상태
 *
 * NULL
 * pending
 * approved
 * rejected
 * ========================================================= */

function gamelab_tester_status(
    string $email
): ?string {

    $email =
        gamelab_normalize_email(
            $email
        );


    if ($email === '') {

        return null;
    }


    $pdo =
        gamelab_db();


    $stmt =
        $pdo->prepare(
            '
            SELECT status
            FROM gamelab_testers
            WHERE email = ?
            LIMIT 1
            '
        );


    $stmt->execute([
        $email
    ]);


    $status =
        $stmt->fetchColumn();


    if ($status === false) {

        return null;
    }


    return (string)$status;
}


/* =========================================================
 * 테스터 레코드 존재 확인
 * ========================================================= */

function gamelab_has_tester_record(
    string $email
): bool {

    return
        gamelab_tester_status(
            $email
        ) !== null;
}


/* =========================================================
 * 테스터 신청
 *
 * 이미 동일 이메일이 존재하면
 * 추가하지 않음
 * ========================================================= */

function gamelab_create_tester_request(
    string $email,
    string $googleSub
): bool {

    $email =
        gamelab_normalize_email(
            $email
        );


    $googleSub =
        trim($googleSub);


    if ($email === '') {

        return false;
    }


    $pdo =
        gamelab_db();


    $stmt =
        $pdo->prepare(
            '
            INSERT IGNORE INTO gamelab_testers
            (
                email,
                google_sub,
                status,
                source,
                requested_at
            )
            VALUES
            (
                ?,
                ?,
                \'pending\',
                \'request\',
                NOW()
            )
            '
        );


    $stmt->execute([
        $email,
        $googleSub !== ''
            ? $googleSub
            : null
    ]);


    return
        $stmt->rowCount() === 1;
}