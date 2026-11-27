-- STIMPACK GAME LAB / add optional Google Play link to development test cards
-- Apply once to the existing gamelab DB, after projects/media migrations.
-- This does not change existing registration, ownership, or participation records.
-- Example: mysql gamelab < /var/www/gamelab/sql/20261127_devtest_google_play.sql

ALTER TABLE gamelab_devtest_projects
    ADD COLUMN play_url VARCHAR(500) NULL AFTER video_url;
