-- STIMPACK GAME LAB / 자체 개발테스트 센터
-- Execute once against the existing GameLab MySQL/MariaDB database.
-- Example: mysql gamelab < sql/20261127_devtest_projects.sql
-- Does not use Play Store membership, installs, ratings or reviews.

CREATE TABLE IF NOT EXISTS gamelab_devtest_projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_google_sub VARCHAR(128) NOT NULL,
    owner_email VARCHAR(254) NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    recruited_count INT UNSIGNED NOT NULL DEFAULT 0,
    target_count INT UNSIGNED NOT NULL DEFAULT 12,
    status ENUM('recruiting', 'testing', 'completed') NOT NULL DEFAULT 'recruiting',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_devtest_status_updated (status, updated_at, id),
    KEY ix_devtest_owner (owner_google_sub, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One active owner per Google account, enforced by a PRIMARY KEY rather than
-- by UI validation alone; concurrent registration requests cannot bypass it.
-- A project is inserted + a lock acquired in a single DB transaction.
-- The lock is removed in the same transaction that marks the project completed.
CREATE TABLE IF NOT EXISTS gamelab_devtest_active_owners (
    owner_google_sub VARCHAR(128) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (owner_google_sub),
    UNIQUE KEY uq_devtest_active_project (project_id),
    CONSTRAINT fk_devtest_active_project FOREIGN KEY (project_id)
        REFERENCES gamelab_devtest_projects (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
