-- Add profile_picture column to users table
-- Run in Supabase Dashboard → SQL Editor

ALTER TABLE users
ADD COLUMN profile_picture VARCHAR(255);

-- Create profile_pictures directory note
-- Note: Profile pictures will be stored in Assets/profile_pictures/ directory
