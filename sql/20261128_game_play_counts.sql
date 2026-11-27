-- GAME LAB: persistent play-attempt counters for publicly listed web games.
-- Apply once BEFORE deploying the updated api/game_click.php.
-- Existing data/game_clicks.json is retained as a read-only legacy baseline.
CREATE TABLE IF NOT EXISTS gamelab_game_play_counts (
    slug VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    play_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
