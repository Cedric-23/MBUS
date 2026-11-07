-- Canonical Morong–SBMA route pins. Harbor Point is the mapped National Book Store frontage,
-- not a surveyed curb coordinate; see docs/route-terminal-research.md before using for turn-by-turn pickup.
UPDATE public.routes
SET origin_lat = 14.6798552,
    origin_lng = 120.2728757
WHERE lower(origin) LIKE '%morong%'
  AND (lower(origin) LIKE '%terminal%' OR lower(origin) LIKE '%bataan%');

UPDATE public.routes
SET destination_lat = 14.6798552,
    destination_lng = 120.2728757
WHERE lower(destination) LIKE '%morong%'
  AND (lower(destination) LIKE '%terminal%' OR lower(destination) LIKE '%bataan%');

UPDATE public.routes
SET origin_lat = 14.8243652,
    origin_lng = 120.2801434
WHERE lower(origin) IN ('sbma', 'sbma terminal')
   OR lower(origin) LIKE 'sbma e-bus stop%'
   OR lower(origin) LIKE '%harbor point%'
   OR lower(origin) LIKE '%national bookstore%';

UPDATE public.routes
SET destination_lat = 14.8243652,
    destination_lng = 120.2801434
WHERE lower(destination) IN ('sbma', 'sbma terminal')
   OR lower(destination) LIKE 'sbma e-bus stop%'
   OR lower(destination) LIKE '%harbor point%'
   OR lower(destination) LIKE '%national bookstore%';
