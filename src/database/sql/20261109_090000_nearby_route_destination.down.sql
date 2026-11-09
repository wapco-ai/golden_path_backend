-- Purpose: remove the MPR nearby destination function.
-- Dependencies: deploy a backend that no longer calls it before rollback.
-- Object: fn_route_nearby_edges. Stored routing geometry is preserved.
BEGIN;
DROP FUNCTION public.fn_route_nearby_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry);
COMMIT;
