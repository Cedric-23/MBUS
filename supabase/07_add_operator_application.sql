-- Add operator application table for driver/operator approval workflow
-- Run in Supabase Dashboard → SQL Editor

CREATE TYPE application_status AS ENUM ('Pending', 'Approved', 'Rejected');

CREATE TABLE operator_application (
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
    application_status application_status DEFAULT 'Pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP,
    reviewed_by INT REFERENCES users(user_id) ON DELETE SET NULL,
    rejection_reason TEXT
);

-- Add application status to users table
ALTER TABLE users
ADD COLUMN application_status application_status DEFAULT NULL;

-- Create indexes for faster queries
CREATE INDEX idx_operator_application_user ON operator_application(user_id);
CREATE INDEX idx_operator_application_status ON operator_application(application_status);

-- Add comment
COMMENT ON TABLE operator_application IS 'Stores driver/operator applications for admin approval';
