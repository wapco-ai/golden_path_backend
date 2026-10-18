-- Purpose: record cultural content creation without inventing dates for legacy rows.
-- Dependencies: the baseline public.contents table and its (poi_id, lang) unique key.
-- Affected objects: public.contents.created_at (nullable timestamptz).
-- Routing impact: none; geometry, graph, and POI membership are unchanged.
-- Rollback: use the matching down file after reverting the timestamp API code.
BEGIN;

-- Add without a default first: existing rows must remain unknown (NULL).
ALTER TABLE public.contents
    ADD COLUMN IF NOT EXISTS created_at timestamp with time zone NULL;

-- Only subsequent inserts receive a database timestamp; no historical backfill.
ALTER TABLE public.contents
    ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP;

COMMIT;
