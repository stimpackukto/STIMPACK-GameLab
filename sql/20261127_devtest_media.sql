-- STIMPACK GAME LAB / 개발테스트 신청 접수 이메일 및 미디어 필드
-- Run ONCE after sql/20261127_devtest_projects.sql has been applied.
-- Existing projects remain intact; nullable for previously registered rows.
-- Example: mysql gamelab < sql/20261127_devtest_media.sql

ALTER TABLE gamelab_devtest_projects
    ADD COLUMN contact_email VARCHAR(254) NULL AFTER target_count,
    ADD COLUMN image_path VARCHAR(255) NULL AFTER contact_email,
    ADD COLUMN video_url VARCHAR(500) NULL AFTER image_path;
