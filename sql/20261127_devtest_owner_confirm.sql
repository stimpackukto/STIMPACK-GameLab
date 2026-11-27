-- One-time migration for owner acknowledgement of tester application emails.
-- Preserves all existing applications, including previously mailed requests.
-- Apply BEFORE deploying the PHP code using owner_confirmed_at.
ALTER TABLE gamelab_devtest_applications
    ADD COLUMN owner_confirmed_at DATETIME NULL AFTER mail_sent_at;
