-- One-time migration: secret token hash for one-click confirmation from owner email.
-- Requires sql/20261127_devtest_owner_confirm.sql to be installed first.
-- Existing applications are preserved. Prior email links without a token
-- cannot confirm automatically.
ALTER TABLE gamelab_devtest_applications
    ADD COLUMN owner_confirm_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER owner_confirmed_at;
