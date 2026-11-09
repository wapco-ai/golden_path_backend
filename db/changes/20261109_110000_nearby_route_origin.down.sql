-- Purpose: remove the nearby origin resolver.
-- Dependency: remove its application caller before rollback.
-- Object: fn_route_nearby_origin_edges. No routing graph data is changed.
BEGIN;
DROP FUNCTION public.fn_route_nearby_origin_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry);
COMMIT;
