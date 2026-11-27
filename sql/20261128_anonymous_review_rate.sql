-- Anonymous feedback rate limiting for the FamilySafe app.
-- Deploy to the GameLab database before deploying api/anonymous_review.php.
CREATE TABLE IF NOT EXISTS gamelab_anonymous_review_rate (
    ip_hash CHAR(64) NOT NULL,
    window_hour BIGINT UNSIGNED NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (ip_hash, window_hour),
    KEY idx_window_hour (window_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
