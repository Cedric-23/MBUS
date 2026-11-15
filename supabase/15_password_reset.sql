-- Password reset fields required by forgot_password.php and verify_code.php.
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_code VARCHAR(6);
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expiry TIMESTAMP NULL;
CREATE INDEX IF NOT EXISTS users_reset_code_idx ON users (reset_code);
