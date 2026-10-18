-- Revert the manual activation API code first. This drops only activation history.
-- Users, current statuses, OTP locks, login data and registration dates are preserved.
BEGIN;

ALTER TABLE public.users DROP COLUMN IF EXISTS last_manual_activation_at;

COMMIT;
