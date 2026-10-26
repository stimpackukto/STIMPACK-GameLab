-- Execute once against the GAME LAB database after backup.
CREATE TABLE IF NOT EXISTS janggi_friend_invitations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 sender_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receiver_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 room_id BIGINT UNSIGNED NULL,
 sender_token VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
 receiver_token VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
 status ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_receiver_status(receiver_key,status),
 INDEX idx_sender_status(sender_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
