-- Remove only the private destination router.
BEGIN;
DROP FUNCTION public.fn_route_private_area_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,bigint,bigint);
COMMIT;
