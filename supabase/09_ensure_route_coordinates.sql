-- Safe to run more than once (IF NOT EXISTS).
-- Adds the map coordinate columns the commuter Schedule / Reserve pages read from `routes`.
-- Run in Supabase Dashboard -> SQL Editor if the live database never ran 03_add_map_coordinates.sql.

ALTER TABLE routes ADD COLUMN IF NOT EXISTS origin_lat DECIMAL(10, 8);
ALTER TABLE routes ADD COLUMN IF NOT EXISTS origin_lng DECIMAL(11, 8);
ALTER TABLE routes ADD COLUMN IF NOT EXISTS destination_lat DECIMAL(10, 8);
ALTER TABLE routes ADD COLUMN IF NOT EXISTS destination_lng DECIMAL(11, 8);

CREATE INDEX IF NOT EXISTS idx_routes_origin_coords ON routes(origin_lat, origin_lng);
CREATE INDEX IF NOT EXISTS idx_routes_destination_coords ON routes(destination_lat, destination_lng);
