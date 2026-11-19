-- Exact map pins selected during reservation; null values retain legacy stop-only bookings.
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS pickup_latitude DOUBLE PRECISION;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS pickup_longitude DOUBLE PRECISION;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS destination_latitude DOUBLE PRECISION;
ALTER TABLE reservation ADD COLUMN IF NOT EXISTS destination_longitude DOUBLE PRECISION;
