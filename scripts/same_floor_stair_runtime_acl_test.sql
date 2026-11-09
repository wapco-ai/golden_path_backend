\set ON_ERROR_STOP on

DO $guard$
BEGIN
    IF right(current_database(), 5) <> '_test' THEN
        RAISE EXCEPTION 'Use a disposable test database for the stair ACL regression';
    END IF;
END;
$guard$;

CREATE ROLE gp_sfs_acl_test_app NOLOGIN;
CREATE ROLE gp_sfs_acl_test_queue NOLOGIN;
CREATE ROLE gp_sfs_acl_test_area_reader NOLOGIN;

GRANT USAGE ON SCHEMA public TO gp_sfs_acl_test_app;
GRANT EXECUTE ON FUNCTION public.fn_allowed_areas(timestamptz, public.gender_enum, text, smallint)
    TO gp_sfs_acl_test_app, gp_sfs_acl_test_area_reader;
GRANT EXECUTE ON FUNCTION public.fn_routing_edges_live_param(timestamptz, public.gender_enum, text, smallint)
    TO gp_sfs_acl_test_app;
GRANT SELECT, INSERT, UPDATE ON public.routing_connectors TO gp_sfs_acl_test_app;
GRANT SELECT, UPDATE ON public.routing_connectors TO gp_sfs_acl_test_queue;
GRANT SELECT ON public.areas, public.door_access_points, public.routing_nodes,
    public.routing_edges_static, public.same_floor_stairs, public.admin_restrictions TO gp_sfs_acl_test_app;

-- Production creates the helpers without default PUBLIC execution.
REVOKE EXECUTE ON FUNCTION
    public.fn_same_floor_stair_landing_areas(smallint),
    public.fn_same_floor_stair_access(bigint, timestamptz, public.gender_enum, text),
    public.fn_same_floor_stair_candidate(bigint, smallint, bigint, bigint, bigint, bigint),
    public.fn_same_floor_stair_edge_geom(bigint)
FROM PUBLIC;

\ir ../db/changes/20261109_150000_same_floor_stair_runtime_acl.up.sql

BEGIN;
SET LOCAL ROLE gp_sfs_acl_test_app;
SELECT count(*) FROM public.fn_same_floor_stair_landing_areas(0::smallint);
SELECT count(*) FROM public.fn_same_floor_stair_access(-1, now(), 'female', 'walk');
SELECT count(*) FROM public.fn_same_floor_stair_candidate(-1, 0::smallint, -1, -2, -1, -2);
SELECT public.fn_same_floor_stair_edge_geom(-1) IS NULL;
ROLLBACK;

DO $check$
BEGIN
    IF NOT has_function_privilege('gp_sfs_acl_test_area_reader',
        'public.fn_same_floor_stair_landing_areas(smallint)', 'EXECUTE') OR EXISTS (
        SELECT 1
        FROM (VALUES
            ('public.fn_same_floor_stair_landing_areas(smallint)'),
            ('public.fn_same_floor_stair_access(bigint,timestamptz,public.gender_enum,text)'),
            ('public.fn_same_floor_stair_candidate(bigint,smallint,bigint,bigint,bigint,bigint)'),
            ('public.fn_same_floor_stair_edge_geom(bigint)')
        ) helpers(signature)
        WHERE has_function_privilege('gp_sfs_acl_test_queue', signature, 'EXECUTE')
            OR (signature <> 'public.fn_same_floor_stair_landing_areas(smallint)'
                AND has_function_privilege('gp_sfs_acl_test_area_reader', signature, 'EXECUTE'))
    ) THEN
        RAISE EXCEPTION 'Same-floor helper grants exceed the existing caller permissions';
    END IF;
END;
$check$;

\ir ../db/changes/20261109_150000_same_floor_stair_runtime_acl.down.sql

DO $check$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM (VALUES
            ('public.fn_same_floor_stair_landing_areas(smallint)'),
            ('public.fn_same_floor_stair_access(bigint,timestamptz,public.gender_enum,text)'),
            ('public.fn_same_floor_stair_candidate(bigint,smallint,bigint,bigint,bigint,bigint)'),
            ('public.fn_same_floor_stair_edge_geom(bigint)')
        ) helpers(signature)
        WHERE has_function_privilege('gp_sfs_acl_test_app', signature, 'EXECUTE')
            OR has_function_privilege('gp_sfs_acl_test_area_reader', signature, 'EXECUTE')
    ) THEN
        RAISE EXCEPTION 'Same-floor helper grants remain after rollback';
    END IF;
END;
$check$;

DROP OWNED BY gp_sfs_acl_test_app, gp_sfs_acl_test_queue, gp_sfs_acl_test_area_reader;
DROP ROLE gp_sfs_acl_test_app, gp_sfs_acl_test_queue, gp_sfs_acl_test_area_reader;
