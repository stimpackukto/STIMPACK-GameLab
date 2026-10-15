-- Run against the database already configured in /etc/stimpack/gamelab.php via gamelab_db().
-- HousingStudy board data is isolated from the existing GameLab community.
CREATE TABLE IF NOT EXISTS housingstudy_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    author_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    author_name VARCHAR(60) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT '자유',
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    resource_url VARCHAR(2000) DEFAULT NULL,
    attachment_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    attachment_name VARCHAR(180) DEFAULT NULL,
    attachment_mime VARCHAR(60) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    INDEX idx_visible_latest (deleted_at, id),
    INDEX idx_author_time (author_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS housingstudy_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    author_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    author_name VARCHAR(60) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    INDEX idx_post_visible (post_id, deleted_at, id),
    CONSTRAINT fk_housingstudy_comment_post FOREIGN KEY (post_id)
        REFERENCES housingstudy_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS housingstudy_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    reporter_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(240) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reporter_post (post_id, reporter_hash),
    INDEX idx_report_time (created_at),
    CONSTRAINT fk_housingstudy_report_post FOREIGN KEY (post_id)
        REFERENCES housingstudy_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS housingstudy_blocks (
    blocker_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    blocked_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (blocker_hash, blocked_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Android-native Credential Manager sign-in.
-- Transient nonce allows server-side ID-token replay protection.
CREATE TABLE IF NOT EXISTS housingstudy_login_nonces (
    nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    expires_at DATETIME NOT NULL,
    INDEX idx_expiration (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authenticated Android sessions, independent of website cookie sessions.
-- Raw bearer secrets and Google ID tokens are NEVER stored in DB.
CREATE TABLE IF NOT EXISTS housingstudy_app_sessions (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    author_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    author_name VARCHAR(60) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expiration (expires_at),
    INDEX idx_author (author_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
