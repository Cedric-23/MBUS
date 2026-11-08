-- Keep profile pictures in the database so they survive Render redeploys/idle spin-downs
-- (the container disk is wiped, which is why pictures kept reverting to the default).
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture_b64 TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture_mime VARCHAR(50);
