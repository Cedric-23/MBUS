-- Add latitude/longitude fields to routes table for Google Maps integration
-- Run in Supabase Dashboard → SQL Editor

ALTER TABLE routes
ADD COLUMN origin_lat DECIMAL(10, 8),
ADD COLUMN origin_lng DECIMAL(11, 8),
ADD COLUMN destination_lat DECIMAL(10, 8),
ADD COLUMN destination_lng DECIMAL(11, 8);

-- Add index for faster queries on coordinates
CREATE INDEX idx_routes_origin_coords ON routes(origin_lat, origin_lng);
CREATE INDEX idx_routes_destination_coords ON routes(destination_lat, destination_lng);
