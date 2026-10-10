-- STIMPACK GAME LAB ↔ WoW account association
-- Run in the GAME LAB database used by gamelab_db().
-- Do not create this in TrinityCore auth/characters/world schemas.
CREATE TABLE IF NOT EXISTS gamelab_wow_google_links (
    google_sub VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    wow_account_id INT UNSIGNED NOT NULL,
    google_email VARCHAR(254) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (google_sub),
    UNIQUE KEY uq_wow_account_id (wow_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
