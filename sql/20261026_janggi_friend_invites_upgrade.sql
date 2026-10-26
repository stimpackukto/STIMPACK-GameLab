-- Apply ONLY if janggi_friend_invitations was created before room/session columns existed.
-- Inspect SHOW COLUMNS first; do not rerun this ALTER after success.
ALTER TABLE janggi_friend_invitations
 ADD COLUMN room_id BIGINT UNSIGNED NULL AFTER receiver_key,
 ADD COLUMN sender_token VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER room_id,
 ADD COLUMN receiver_token VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER sender_token;
