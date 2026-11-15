-- Add operator application table for driver/operator approval workflow
-- Run in Supabase Dashboard → SQL Editor

DO $$
BEGIN
    CREATE TYPE application_status AS ENUM ('Pending', 'Approved', 'Rejected');
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

CREATE TABLE IF NOT EXISTS operator_application (
    application_id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(user_id) ON DELETE CASCADE,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20),
    address TEXT,
    license_number VARCHAR(100),
    license_expiry DATE,
    vehicle_type VARCHAR(100),
    vehicle_plate_number VARCHAR(50),
    license_document_path VARCHAR(255),
    vehicle_document_path VARCHAR(255),
    license_document_b64 TEXT,
    license_document_mime VARCHAR(50),
    vehicle_document_b64 TEXT,
    vehicle_document_mime VARCHAR(50),
    application_status application_status DEFAULT 'Pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP,
    reviewed_by INT REFERENCES users(user_id) ON DELETE SET NULL,
    rejection_reason TEXT
);

-- Add application status to users table
ALTER TABLE users
ADD COLUMN IF NOT EXISTS application_status application_status DEFAULT NULL;

-- Create indexes for faster queries
CREATE INDEX IF NOT EXISTS idx_operator_application_user ON operator_application(user_id);
CREATE INDEX IF NOT EXISTS idx_operator_application_status ON operator_application(application_status);

-- Keep existing installations compatible with the database-backed document storage.
ALTER TABLE operator_application ADD COLUMN IF NOT EXISTS license_document_b64 TEXT;
ALTER TABLE operator_application ADD COLUMN IF NOT EXISTS license_document_mime VARCHAR(50);
ALTER TABLE operator_application ADD COLUMN IF NOT EXISTS vehicle_document_b64 TEXT;
ALTER TABLE operator_application ADD COLUMN IF NOT EXISTS vehicle_document_mime VARCHAR(50);

-- Add comment
COMMENT ON TABLE operator_application IS 'Stores driver/operator applications for admin approval';
