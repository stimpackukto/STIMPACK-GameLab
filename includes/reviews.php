<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!function_exists('review_count')) {

    function review_count(
        string $type,
        string $contentId
    ): int {

        $pdo = gamelab_db();

        $stmt = $pdo->prepare(
            '
            SELECT COUNT(*)
            FROM gamelab_reviews
            WHERE content_type = ?
              AND content_id = ?
              AND parent_id IS NULL
            '
        );

        $stmt->execute([
            $type,
            $contentId
        ]);

        return (int)$stmt->fetchColumn();
    }
}


if (!function_exists('review_list')) {

    function review_list(
        string $type,
        string $contentId
    ): array {

        $pdo = gamelab_db();

        $stmt = $pdo->prepare(
            '
            SELECT *
            FROM gamelab_reviews
            WHERE content_type = ?
              AND content_id = ?
            ORDER BY
                CASE
                    WHEN parent_id IS NULL THEN id
                    ELSE parent_id
                END DESC,
                parent_id IS NOT NULL ASC,
                id ASC
            '
        );

        $stmt->execute([
            $type,
            $contentId
        ]);

        return $stmt->fetchAll();
    }
}
