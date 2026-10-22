-- Run once in the same database used by gamelab_db().
-- No raw Google sub, email, user name or bearer token is stored.
CREATE TABLE IF NOT EXISTS gamelab_janggi_device_sessions (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  account_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_janggi_device_account (account_key),
  KEY idx_janggi_device_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recommended daily maintenance on server:
-- DELETE FROM gamelab_janggi_device_sessions WHERE expires_at < UTC_TIMESTAMP();
