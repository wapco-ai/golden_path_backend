-- Purpose: store only actual manual admin activation transitions for end users.
-- Dependencies: immutable baseline public.users.
-- Objects: public.users.last_manual_activation_at nullable timestamptz.
-- Routing/auth impact: none; no user status, OTP lock or login data is changed.
-- No default/backfill: registration and old users have no manual activation history.
BEGIN;

ALTER TABLE public.users ADD COLUMN IF NOT EXISTS last_manual_activation_at timestamp with time zone NULL;

COMMIT;
