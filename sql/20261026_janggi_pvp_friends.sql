-- Apply after pvp-schema.sql and pvp-dice-migration.sql.
-- A verified Google account is required before friends can be added.
ALTER TABLE janggi_pvp_players ADD COLUMN account_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
CREATE INDEX idx_janggi_pvp_player_account ON janggi_pvp_players(account_key);
CREATE TABLE IF NOT EXISTS janggi_friend_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sender_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receiver_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender_name VARCHAR(64) NOT NULL DEFAULT '플레이어',
 receiver_name VARCHAR(64) NOT NULL DEFAULT '플레이어',
 status ENUM('pending','accepted','declined','removed') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY unique_sender_receiver(sender_key,receiver_key),
 INDEX idx_friend_receiver(receiver_key,status),
 INDEX idx_friend_sender(sender_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
