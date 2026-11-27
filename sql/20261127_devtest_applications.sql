-- STIMPACK GAME LAB / independent website development-test applications
-- Run once after sql/20261127_devtest_projects.sql.
-- Does not change existing projects, Google Play participation, or rewards.

CREATE TABLE IF NOT EXISTS gamelab_devtest_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    applicant_google_sub VARCHAR(128) NOT NULL,
    applicant_email VARCHAR(254) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    mail_sent_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devtest_application_account (project_id, applicant_google_sub),
    KEY ix_devtest_application_requester (applicant_google_sub, requested_at),
    CONSTRAINT fk_devtest_application_project
        FOREIGN KEY (project_id) REFERENCES gamelab_devtest_projects (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
