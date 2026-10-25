-- One-time Chrome -> WebView authenticated account handoff.
CREATE TABLE IF NOT EXISTS gamelab_janggi_webview_pairs (
 pair_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 INDEX idx_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
