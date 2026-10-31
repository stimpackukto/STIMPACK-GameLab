-- Run once in the GAMELAB application's MySQL database.
-- Existing music and legacy music/data/comments.php stay unchanged.
CREATE TABLE IF NOT EXISTS gamelab_music_comments (
 id CHAR(24) NOT NULL,
 track_id CHAR(32) NOT NULL,
 body TEXT NOT NULL,
 name VARCHAR(120) NOT NULL,
 owner VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_music_track_created (track_id, created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
