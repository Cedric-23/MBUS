-- Per-ticket passenger type and discount ID (also auto-applied by config/db_connect.php)
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS passenger_type VARCHAR(20) DEFAULT 'Regular';
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS discount_status VARCHAR(20) DEFAULT 'None';
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS id_image_b64 TEXT;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS id_image_mime VARCHAR(50);
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS fare_amount NUMERIC(10,2);
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS discount_amount NUMERIC(10,2);
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS discount_reviewed_by INT;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS discount_reviewed_at TIMESTAMP;
