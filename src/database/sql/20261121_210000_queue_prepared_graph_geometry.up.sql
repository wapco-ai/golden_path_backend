-- One atomic statement for standalone SQL and the migration's own transaction.
DO $grant$
DECLARE
    helpers constant text[] := ARRAY[
        'public.fn_build_intra_area_edge_prepared(bigint,smallint,geometry,geometry,double precision,geometry,geometry,geometry,geometry,geometry,geometry)',
        'public.fn_route_line_inside_prepared(geometry,geometry,geometry,double precision)',
        'public.fn_route_visibility_prepared(bigint,geometry,geometry,geometry,geometry)'
    ];
    signature text;
    helper regprocedure;
BEGIN
    IF to_regrole('gp_queue_runtime') IS NULL THEN RETURN; END IF;

    -- Older installations and a full rollback may have none of these functions.
    IF NOT EXISTS (SELECT FROM unnest(helpers) s WHERE to_regprocedure(s) IS NOT NULL)
       AND NOT EXISTS (SELECT FROM pg_proc WHERE pronamespace='public'::regnamespace
           AND proname='fn_build_intra_area_edge_geom' AND prosrc LIKE '%fn_build_intra_area_edge_prepared(%')
    THEN RETURN; END IF;

    FOREACH signature IN ARRAY helpers LOOP
        helper := to_regprocedure(signature);
        IF helper IS NULL THEN
            RAISE EXCEPTION 'Incomplete graph helper installation: missing %', signature;
        END IF;
        IF NOT has_function_privilege('gp_queue_runtime', helper, 'EXECUTE') THEN
            EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO gp_queue_runtime', helper);
            -- A non-owner may receive a warning instead of an error from GRANT.
            IF NOT has_function_privilege('gp_queue_runtime', helper, 'EXECUTE') THEN
                RAISE EXCEPTION 'Migration could not grant graph worker EXECUTE on %', helper;
            END IF;
        END IF;
    END LOOP;
END;
$grant$;
