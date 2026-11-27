<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Self-hosted GameLab development tests; independent of Google Play programs.
 * The account key comes ONLY from the server-side, Google-verified OAuth session.
 */
function gamelab_devtest_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $user = $_SESSION['google_user'] ?? null;
    if (!is_array($user)) {
        return null;
    }
    $sub = trim((string)($user['sub'] ?? ''));
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($sub === '' || strlen($sub) > 128 || $email === '' || strlen($email) > 254) {
        return null;
    }
    return ['sub' => $sub, 'email' => $email];
}

function gamelab_devtest_number(mixed $value, string $label, int $min, int $max): int
{
    $raw = trim((string)$value);
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 8) {
        throw new RuntimeException($label . '은(는) 숫자로 입력해 주세요.');
    }
    $number = (int)$raw;
    if ($number < $min || $number > $max) {
        throw new RuntimeException($label . '은(는) ' . $min . '~' . $max . ' 범위로 입력해 주세요.');
    }
    return $number;
}

function gamelab_devtest_form_values(array $input): array
{
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $titleLen = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
    $descriptionLen = function_exists('mb_strlen')
        ? mb_strlen($description, 'UTF-8') : strlen($description);
    if ($titleLen < 2 || $titleLen > 100) {
        throw new RuntimeException('앱 이름은 2~100자로 입력해 주세요.');
    }
    if ($descriptionLen < 10 || $descriptionLen > 2500) {
        throw new RuntimeException('앱 설명은 10~2500자로 입력해 주세요.');
    }
    $recruited = gamelab_devtest_number($input['recruited_count'] ?? '', '현재 모집 인원', 0, 100000);
    $target = gamelab_devtest_number($input['target_count'] ?? '', '목표 모집 인원', 1, 100000);
    return [
        'title' => $title,
        'description' => $description,
        'recruited_count' => $recruited,
        'target_count' => $target,
    ];
}

function gamelab_devtest_active(PDO $db, string $sub): ?array
{
    $stmt = $db->prepare('
        SELECT p.id, p.title, p.status
        FROM gamelab_devtest_active_owners a
        INNER JOIN gamelab_devtest_projects p ON p.id = a.project_id
        WHERE a.owner_google_sub = ?
        LIMIT 1
    ');
    $stmt->execute([$sub]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function gamelab_devtest_counts(PDO $db): array
{
    $counts = ['recruiting' => 0, 'testing' => 0, 'completed' => 0];
    foreach ($db->query('SELECT status, COUNT(*) AS amount FROM gamelab_devtest_projects GROUP BY status') as $row) {
        if (array_key_exists((string)$row['status'], $counts)) {
            $counts[(string)$row['status']] = (int)$row['amount'];
        }
    }
    return $counts;
}

function gamelab_devtest_list(PDO $db, string $status): array
{
    if (!in_array($status, ['recruiting', 'testing', 'completed'], true)) {
        throw new RuntimeException('잘못된 테스트 상태입니다.');
    }
    $stmt = $db->prepare('
        SELECT id, owner_google_sub, title, description, recruited_count, target_count,
               status, created_at, started_at, completed_at
        FROM gamelab_devtest_projects
        WHERE status = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 200
    ');
    $stmt->execute([$status]);
    return $stmt->fetchAll();
}

function gamelab_devtest_owned_project(PDO $db, int $id, string $sub, bool $lock = false): array
{
    $stmt = $db->prepare('
        SELECT id, owner_google_sub, title, description, recruited_count, target_count, status
        FROM gamelab_devtest_projects
        WHERE id = ? AND owner_google_sub = ?
        LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$id, $sub]);
    $project = $stmt->fetch();
    if ($project === false) {
        throw new RuntimeException('본인이 등록한 앱에서만 이 작업을 할 수 있습니다.');
    }
    return $project;
}

function gamelab_devtest_create(PDO $db, array $user, array $values): void
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            INSERT INTO gamelab_devtest_projects
            (owner_google_sub, owner_email, title, description, recruited_count, target_count, status)
            VALUES (?, ?, ?, ?, ?, ?, \'recruiting\')
        ');
        $stmt->execute([
            $user['sub'], $user['email'], $values['title'], $values['description'],
            $values['recruited_count'], $values['target_count'],
        ]);
        $projectId = (int)$db->lastInsertId();
        // This unique lock is the authoritative one-active-app constraint.
        $lock = $db->prepare('
            INSERT INTO gamelab_devtest_active_owners (owner_google_sub, project_id)
            VALUES (?, ?)
        ');
        $lock->execute([$user['sub'], $projectId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            throw new RuntimeException('이미 진행 중인 앱이 있습니다. 해당 테스트를 완료한 다음 등록할 수 있습니다.');
        }
        throw $error;
    }
}

function gamelab_devtest_edit(PDO $db, array $user, int $id, array $values): void
{
    $db->beginTransaction();
    try {
        $project = gamelab_devtest_owned_project($db, $id, $user['sub'], true);
        if ($project['status'] === 'completed') {
            throw new RuntimeException('완료된 테스트는 수정할 수 없습니다.');
        }
        $stmt = $db->prepare('
            UPDATE gamelab_devtest_projects
            SET title = ?, description = ?, recruited_count = ?, target_count = ?
            WHERE id = ? AND owner_google_sub = ? AND status IN (\'recruiting\', \'testing\')
        ');
        $stmt->execute([
            $values['title'], $values['description'],
            $values['recruited_count'], $values['target_count'], $id, $user['sub'],
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function gamelab_devtest_transition(PDO $db, array $user, int $id, string $action): void
{
    $expected = $action === 'start' ? 'recruiting' : ($action === 'complete' ? 'testing' : '');
    if ($expected === '') {
        throw new RuntimeException('허용되지 않은 상태 변경입니다.');
    }
    $db->beginTransaction();
    try {
        $project = gamelab_devtest_owned_project($db, $id, $user['sub'], true);
        if ($project['status'] !== $expected) {
            throw new RuntimeException('현재 단계에서는 해당 버튼을 사용할 수 없습니다.');
        }
        if ($action === 'start') {
            $stmt = $db->prepare('
                UPDATE gamelab_devtest_projects
                SET status = \'testing\', started_at = NOW()
                WHERE id = ? AND owner_google_sub = ? AND status = \'recruiting\'
            ');
        } else {
            $stmt = $db->prepare('
                UPDATE gamelab_devtest_projects
                SET status = \'completed\', completed_at = NOW()
                WHERE id = ? AND owner_google_sub = ? AND status = \'testing\'
            ');
        }
        $stmt->execute([$id, $user['sub']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('상태 변경이 반영되지 않았습니다. 다시 확인해 주세요.');
        }
        if ($action === 'complete') {
            $lock = $db->prepare('
                DELETE FROM gamelab_devtest_active_owners
                WHERE owner_google_sub = ? AND project_id = ?
            ');
            $lock->execute([$user['sub'], $id]);
            if ($lock->rowCount() !== 1) {
                throw new RuntimeException('활성 등록 제한을 해제하지 못했습니다.');
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
