-- Driver ID / licence renewals (created automatically by Includes/id_renewal.php; kept here for reference)
CREATE TABLE IF NOT EXISTS id_renewal (
    renewal_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    new_expiry DATE NOT NULL,
    document_b64 TEXT,
    document_mime VARCHAR(50),
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    submitted_at TIMESTAMP NOT NULL DEFAULT NOW(),
    reviewed_at TIMESTAMP,
    reviewed_by INT,
    rejection_reason TEXT
);
CREATE INDEX IF NOT EXISTS id_renewal_user_idx ON id_renewal (user_id, renewal_id DESC);
