-- Driver and conductor applications: the applicant must verify their email BEFORE the application reaches the admin.
-- Existing applications stay visible to the admin (email_verified defaults to TRUE);
-- new applications are saved with email_verified = FALSE until the emailed 6-digit code is entered.
-- Run in Supabase Dashboard -> SQL Editor BEFORE deploying the matching code.

ALTER TABLE operator_application
    ADD COLUMN IF NOT EXISTS email_verified              BOOLEAN   NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS email_verified_at           TIMESTAMP,
    ADD COLUMN IF NOT EXISTS verification_code           VARCHAR(10),
    ADD COLUMN IF NOT EXISTS verification_code_expiry    TIMESTAMP,
    ADD COLUMN IF NOT EXISTS verification_code_attempts INT       NOT NULL DEFAULT 0;

ALTER TABLE conductor_application
    ADD COLUMN IF NOT EXISTS email_verified              BOOLEAN   NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS email_verified_at           TIMESTAMP,
    ADD COLUMN IF NOT EXISTS verification_code           VARCHAR(10),
    ADD COLUMN IF NOT EXISTS verification_code_expiry    TIMESTAMP,
    ADD COLUMN IF NOT EXISTS verification_code_attempts INT       NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_operator_application_verified  ON operator_application(email_verified);
CREATE INDEX IF NOT EXISTS idx_conductor_application_verified ON conductor_application(email_verified);
