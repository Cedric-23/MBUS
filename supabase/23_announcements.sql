-- Transportation updates / service announcements (additive migration).
-- Safe to re-run. A Git push does not apply this; run it in the Supabase SQL Editor.

CREATE TABLE IF NOT EXISTS announcement (
    announcement_id SERIAL PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'Notice',
    audience VARCHAR(20) NOT NULL DEFAULT 'All',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INT REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_announcement_active ON announcement(is_active, created_at DESC);

ALTER TABLE announcement ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE announcement FROM PUBLIC, anon, authenticated;
