-- Persist one authoritative expiry timestamp for pending seat holds.
ALTER TABLE public.reservation
    ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ;

UPDATE public.reservation
SET expires_at = created_at + INTERVAL '10 minutes'
WHERE expires_at IS NULL;

ALTER TABLE public.reservation
    ALTER COLUMN expires_at SET DEFAULT (NOW() + INTERVAL '10 minutes');

CREATE INDEX IF NOT EXISTS reservation_pending_expiry_idx
    ON public.reservation (schedule_id, expires_at)
    WHERE status = 'Pending';
