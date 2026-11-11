-- Restore the prior graph and endpoint function bodies and remove generated leaf connections.
BEGIN;

DO $migration$
DECLARE
    definition text;
    changed text;
    area_hook constant text := E'\n    PERFORM public.fn_attach_one_sided_area_access(p_area_id, v_floor, p_clearance_m);\n';
    area_locks constant text := $locks$
    FOR v_area_id IN SELECT area_id FROM tmp_door_affected_areas ORDER BY area_id LOOP
        PERFORM pg_advisory_xact_lock(hashtextextended('routing-area:' || v_area_id::text, 0));
    END LOOP;$locks$;
    door_summary constant text := $summary$ || CASE
        WHEN v_area_ok > 0 AND v_area_failed = 0 AND v_inserted_forward_edges = 0
          AND public.fn_one_sided_door_connected(p_door_id, v_floor)
        THEN jsonb_build_object('ok', true, 'single_sided_connected', true, 'inserted_nodes', GREATEST(v_inserted_nodes, 1))
        ELSE '{}'::jsonb END$summary$;
    endpoint_access constant text := '(dap.needs_review IS NOT TRUE OR public.fn_is_one_sided_access(dap))';
    live_access constant text := '(COALESCE(dap.needs_review, FALSE) = FALSE OR (public.fn_is_one_sided_access(dap) AND rn.area_id IN (dap.from_area, dap.to_area) AND ST_OrderingEquals(rn.geom, dap.geom)))';
BEGIN
    SELECT pg_get_functiondef('public.fn_rebuild_graph_for_area(bigint,smallint,boolean,double precision,double precision)'::regprocedure) INTO definition;
    changed := replace(definition, area_hook, '');
    IF changed = definition THEN RAISE EXCEPTION 'Missing one-sided area graph hook'; END IF;
    EXECUTE changed;

    SELECT pg_get_functiondef('public.fn_rebuild_graph_for_door(bigint,boolean,double precision,double precision)'::regprocedure) INTO definition;
    changed := replace(replace(definition, door_summary, ''), area_locks, '');
    IF changed = definition THEN RAISE EXCEPTION 'Missing one-sided door graph summary'; END IF;
    EXECUTE changed;

    SELECT pg_get_functiondef('public.fn_routing_edges_live_param(timestamptz,public.gender_enum,text,smallint)'::regprocedure) INTO definition;
    changed := replace(definition, live_access, 'COALESCE(dap.needs_review, FALSE) = FALSE');
    IF changed = definition THEN RAISE EXCEPTION 'Missing one-sided live edge filter'; END IF;
    EXECUTE changed;

    SELECT pg_get_functiondef('public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,geometry,geometry,integer,bigint,bigint)'::regprocedure) INTO definition;
    changed := replace(definition, endpoint_access, 'dap.needs_review IS NOT TRUE');
    IF changed = definition THEN RAISE EXCEPTION 'Missing one-sided exact endpoint filters'; END IF;
    EXECUTE changed;
END;
$migration$;

CREATE TEMP TABLE one_sided_rollback_edges ON COMMIT DROP AS
SELECT id, src AS node_id FROM public.routing_edges_static WHERE attrs ? 'one_sided_access_id';

DELETE FROM public.routing_edge_areas a USING one_sided_rollback_edges e WHERE a.edge_id = e.id;
DELETE FROM public.routing_edges_static a USING one_sided_rollback_edges e WHERE a.id = e.id;
DELETE FROM public.routing_nodes n USING one_sided_rollback_edges e
WHERE n.id = e.node_id AND NOT EXISTS (SELECT 1 FROM public.routing_edges_static other WHERE n.id IN (other.src, other.dst));

DROP FUNCTION public.fn_one_sided_door_connected(bigint,smallint);
DROP FUNCTION public.fn_attach_one_sided_area_access(bigint,smallint,double precision);
DROP FUNCTION public.fn_is_one_sided_access(public.door_access_points);

COMMIT;
