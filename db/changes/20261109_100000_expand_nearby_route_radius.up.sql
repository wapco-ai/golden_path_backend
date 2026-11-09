-- Purpose: include reachable endpoints between 30 and 60 m from an unavailable destination.
-- Dependency: 20261109_090000_nearby_route_destination.
-- Object: fn_route_nearby_edges. The live graph, access rules and geometry are preserved.
-- Rollback: restore the 30 m search radius.
BEGIN;
DO $migration$
DECLARE
  definition text := pg_get_functiondef(
    'public.fn_route_nearby_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)'::regprocedure
  );
  previous CONSTANT text := 'radius_m CONSTANT float8 := 30.0;';
BEGIN
  IF strpos(definition, previous) = 0 THEN
    RAISE EXCEPTION 'Expected the 30 m nearby route radius before migration';
  END IF;
  EXECUTE replace(definition, previous, 'radius_m CONSTANT float8 := 60.0;');
END
$migration$;
COMMIT;
