-- Run once against the existing GAME LAB MySQL database.
-- No Janggi user profile or consent-preference table is created.
-- account_key is HMAC-SHA256 of the Google sub with a SERVER-ONLY secret.
-- Only completed and explicitly consented game records are accepted.
CREATE TABLE IF NOT EXISTS gamelab_janggi_training_records (
  account_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  game_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  consent_version VARCHAR(64) NOT NULL,
  record_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(account_key, game_id),
  INDEX idx_janggi_training_retention(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Set a daily system cron to purge records older than 365 days:
-- php /var/www/gamelab/scripts/janggi_prune_records.php
