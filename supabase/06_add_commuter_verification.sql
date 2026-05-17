-- Add commuter verification table for Student/Senior/PWD discounts
-- Run in Supabase Dashboard → SQL Editor

CREATE TYPE verification_type AS ENUM ('Student', 'Senior', 'PWD');
CREATE TYPE verification_status AS ENUM ('Pending', 'Approved', 'Rejected');

CREATE TABLE commuter_verification (
    verification_id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(user_id) ON DELETE CASCADE,
    verification_type verification_type NOT NULL,
    document_path VARCHAR(255),
    document_number VARCHAR(100),
    expiry_date DATE,
    verification_status verification_status DEFAULT 'Pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP,
    reviewed_by INT REFERENCES users(user_id) ON DELETE SET NULL,
    rejection_reason TEXT
);

-- Add verification status to users table
ALTER TABLE users
ADD COLUMN verification_status verification_status DEFAULT NULL,
ADD COLUMN verification_type verification_type DEFAULT NULL;

-- Create indexes for faster queries
CREATE INDEX idx_verification_user ON commuter_verification(user_id);
CREATE INDEX idx_verification_status ON commuter_verification(verification_status);
CREATE INDEX idx_verification_type ON commuter_verification(verification_type);

-- Add comment
COMMENT ON TABLE commuter_verification IS 'Stores commuter verification documents for discount eligibility';
