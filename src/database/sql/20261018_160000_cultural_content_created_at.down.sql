-- Purpose: remove cultural content creation tracking when rolling back this feature.
-- Dependencies: revert the timestamp API code before applying this rollback.
-- Affected objects: public.contents.created_at; recorded dates are discarded.
-- Routing impact: none; content, POIs, geometry, and graph data are retained.
-- Rollback of this rollback: reapply up; discarded dates cannot be reconstructed.
BEGIN;

ALTER TABLE public.contents DROP COLUMN IF EXISTS created_at;

COMMIT;
