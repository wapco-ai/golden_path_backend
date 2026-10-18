-- Drops recorded category dates only; category rows, translations and IDs remain.
-- Revert the category timestamp API code before applying this rollback.
BEGIN;

ALTER TABLE public.categories DROP COLUMN IF EXISTS created_at;

COMMIT;
