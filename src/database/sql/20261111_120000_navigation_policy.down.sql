BEGIN;
ALTER TABLE public.routing_timing_settings DROP COLUMN navigation_policy, DROP COLUMN navigation_version, DROP COLUMN navigation_updated_at;
COMMIT;
