-- Email verification for newly registered commuter accounts.
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS registration_code VARCHAR(6);
ALTER TABLE users ADD COLUMN IF NOT EXISTS registration_code_expiry TIMESTAMP NULL;
UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE LOWER(user_type) = 'commuter' AND registration_code IS NULL;
CREATE INDEX IF NOT EXISTS users_registration_code_idx ON users (registration_code);
