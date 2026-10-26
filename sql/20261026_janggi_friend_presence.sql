-- Run once after backup of the GAME LAB database.
-- One presence row per verified Janggi account. No device tokens or IP addresses.
CREATE TABLE IF NOT EXISTS janggi_friend_presence (
 account_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 online_since DATETIME NOT NULL,
 last_seen DATETIME NOT NULL,
 INDEX idx_janggi_friend_presence_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
