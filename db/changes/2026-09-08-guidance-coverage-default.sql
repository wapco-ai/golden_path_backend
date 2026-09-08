-- Companion to 2026_09_08_180000_set_guidance_coverage_default_to_100.php.
-- Apply via the targeted Laravel migration OR this SQL, not both workflows.
-- Existing rows and the current > 0 / <= 100 constraint remain unchanged.
BEGIN;
SET LOCAL lock_timeout = '5s';
ALTER TABLE public.guidance_points
    ALTER COLUMN coverage_radius_m SET DEFAULT 100.00;
COMMIT;

-- Code rollback only resets the default; it does not undo administrator edits:
-- ALTER TABLE public.guidance_points ALTER COLUMN coverage_radius_m SET DEFAULT 10.00;
