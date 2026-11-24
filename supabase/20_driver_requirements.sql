-- Driver/operator application: extra requirements sent by the beneficiary
--   * 2x2 picture
--   * medical certificate with drug test
--   * NBI clearance
-- (the updated driver's license already uses license_document_*)
-- Run in Supabase Dashboard -> SQL Editor BEFORE deploying the matching register.php.

ALTER TABLE operator_application
    ADD COLUMN IF NOT EXISTS id_photo_b64        TEXT,
    ADD COLUMN IF NOT EXISTS id_photo_mime       VARCHAR(50),
    ADD COLUMN IF NOT EXISTS medical_document_b64  TEXT,
    ADD COLUMN IF NOT EXISTS medical_document_mime VARCHAR(50),
    ADD COLUMN IF NOT EXISTS nbi_document_b64    TEXT,
    ADD COLUMN IF NOT EXISTS nbi_document_mime   VARCHAR(50);
