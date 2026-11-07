-- Store verification IDs in the database (Render's disk is wiped on redeploy,
-- and Assets/ is publicly served, so files there are neither safe nor durable).
ALTER TABLE commuter_verification ADD COLUMN IF NOT EXISTS document_b64 TEXT;
ALTER TABLE commuter_verification ADD COLUMN IF NOT EXISTS document_mime VARCHAR(50);
