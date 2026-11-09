-- Purpose: restore the 30 m radius for MPR nearby routes.
-- Dependency: 20261109_100000_expand_nearby_route_radius.
-- Object: fn_route_nearby_edges. No graph rebuild is required.
BEGIN;
DO $migration$
DECLARE
  definition text := pg_get_functiondef(
    'public.fn_route_nearby_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)'::regprocedure
  );
  previous CONSTANT text := 'radius_m CONSTANT float8 := 60.0;';
BEGIN
  IF strpos(definition, previous) = 0 THEN
    RAISE EXCEPTION 'Expected the 60 m nearby route radius before rollback';
  END IF;
  EXECUTE replace(definition, previous, 'radius_m CONSTANT float8 := 30.0;');
END
$migration$;
COMMIT;
