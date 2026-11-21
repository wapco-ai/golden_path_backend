-- Allow the graph worker to rebuild area edges after the shared routing geometry migration.
-- The 20261111_150000 endpoint migration installs these helpers together on supported databases.
-- Changes only function execution privileges for the graph queue runtime role.
-- Keep existing operational grants on rollback, including the production repair.
BEGIN;

DO $grant$
BEGIN
    IF to_regrole('gp_queue_runtime') IS NOT NULL
       AND to_regprocedure('public.fn_build_intra_area_edge_prepared(bigint,smallint,geometry,geometry,double precision,geometry,geometry,geometry,geometry,geometry,geometry)') IS NOT NULL THEN
        GRANT EXECUTE ON FUNCTION
            public.fn_build_intra_area_edge_prepared(bigint, smallint, geometry, geometry, double precision,
                geometry, geometry, geometry, geometry, geometry, geometry),
            public.fn_route_line_inside_prepared(geometry, geometry, geometry, double precision),
            public.fn_route_visibility_prepared(bigint, geometry, geometry, geometry, geometry)
        TO gp_queue_runtime;
    END IF;
END;
$grant$;

COMMIT;
