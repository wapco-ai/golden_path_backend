-- Rollback: deploy the previous application first. No data is removed.
BEGIN;
DROP FUNCTION public.fn_route_multifloor_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,geometry,integer);
COMMIT;
