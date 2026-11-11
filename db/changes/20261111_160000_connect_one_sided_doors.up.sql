-- Attach unambiguous one-sided access points to the graph of their own area.
-- Depends on DAP-backed graph builders and exact door routing. Transitions keep their existing rules.
-- The down migration removes the added attachments and restores the installed functions.
BEGIN;

CREATE FUNCTION public.fn_is_one_sided_access(p_access public.door_access_points)
RETURNS boolean LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT num_nonnulls(p_access.from_area, p_access.to_area) = 1
       AND (p_access.review_reason = 'one_sided_door'
            OR (p_access.review_reason IS NULL AND p_access.to_area IS NULL));
$$;

CREATE FUNCTION public.fn_attach_one_sided_area_access(
    p_area_id bigint, p_floor smallint, p_clearance_m double precision
) RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    access_point record;
    attachment record;
    access_node_id bigint;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('routing-area:' || p_area_id::text, 0));
    FOR access_point IN
        SELECT dap.id, dap.geom
        FROM public.door_access_points dap
        JOIN public.areas a ON a.id = p_area_id AND a.floor = dap.floor
        WHERE dap.floor = p_floor
          AND (dap.from_area = p_area_id OR dap.to_area = p_area_id)
          AND dap.needs_review IS TRUE AND public.fn_is_one_sided_access(dap)
          AND public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb)) = 'routable'
          AND ST_DWithin(ST_Boundary(a.geom), dap.geom, 0.02)
          AND NOT EXISTS (SELECT 1 FROM public.routing_connector_stops s WHERE s.door_id = dap.door_id)
        ORDER BY dap.id
    LOOP
        -- An attachment is a leaf of the regular area graph. Pick a safe edge
        -- to a regular node so reviewed access points cannot create shortcuts.
        SELECT n.id AS node_id, path.geom
        INTO attachment
        FROM public.routing_nodes n
        JOIN public.door_access_points peer ON peer.id = n.ref_id AND peer.floor = n.floor
        CROSS JOIN LATERAL (
            SELECT ST_MakeLine(ARRAY[access_point.geom,
                public.fn_dap_node_geom_inside_area(access_point.geom, p_area_id, p_clearance_m),
                n.geom]) AS inset_line
        ) candidate
        CROSS JOIN LATERAL (
            SELECT CASE
                WHEN public.fn_route_line_valid_inside_area(p_area_id, candidate.inset_line, p_clearance_m)
                    THEN candidate.inset_line
                ELSE public.fn_build_intra_area_edge_geom(p_area_id, p_floor, access_point.geom, n.geom, p_clearance_m)
            END AS geom OFFSET 0
        ) path
        WHERE n.area_id = p_area_id AND n.floor = p_floor AND n.ref_table = 'door_access_points'
          AND peer.needs_review IS NOT TRUE AND n.area_id IN (peer.from_area, peer.to_area)
          AND path.geom IS NOT NULL AND ST_Length(path.geom) > 0
          AND public.fn_route_line_valid_inside_area(p_area_id, path.geom, p_clearance_m)
        ORDER BY access_point.geom <-> n.geom, n.id
        LIMIT 1;

        SELECT n.id INTO access_node_id
        FROM public.routing_nodes n
        WHERE n.floor = p_floor AND n.area_id = p_area_id
          AND n.ref_table = 'door_access_points' AND n.ref_id = access_point.id;

        DELETE FROM public.routing_edge_areas a USING public.routing_edges_static e
        WHERE a.edge_id = e.id AND e.src = access_node_id AND e.attrs->>'one_sided_access_id' = access_point.id::text;
        DELETE FROM public.routing_edges_static e
        WHERE e.src = access_node_id AND e.attrs->>'one_sided_access_id' = access_point.id::text;

        IF attachment.node_id IS NULL THEN
            DELETE FROM public.routing_nodes n WHERE n.id = access_node_id
              AND NOT EXISTS (SELECT 1 FROM public.routing_edges_static e WHERE n.id IN (e.src, e.dst));
            CONTINUE;
        END IF;

        IF access_node_id IS NULL THEN
            INSERT INTO public.routing_nodes(floor, geom, kind, ref_table, ref_id, area_id)
            VALUES (p_floor, access_point.geom, 'door_access', 'door_access_points', access_point.id, p_area_id)
            RETURNING id INTO access_node_id;
        ELSE
            -- Reuse the node on area rebuilds.
            UPDATE public.routing_nodes SET geom = access_point.geom WHERE id = access_node_id
              AND NOT ST_OrderingEquals(geom, access_point.geom);
        END IF;

        INSERT INTO public.routing_edges_static(floor, src, dst, geom, base_cost, door_id, attrs)
        VALUES (p_floor, access_node_id, attachment.node_id, attachment.geom,
            GREATEST(ST_Length(attachment.geom), 0.50), NULL,
            jsonb_build_object('edge_type', 'intra_area', 'area_id', p_area_id,
                'valid_inside_area', true, 'clearance_m', p_clearance_m, 'one_sided_access_id', access_point.id));
    END LOOP;
END;
$$;

CREATE FUNCTION public.fn_one_sided_door_connected(p_door_id bigint, p_floor smallint)
RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.door_access_points dap
        JOIN public.routing_nodes n ON n.ref_table = 'door_access_points' AND n.ref_id = dap.id
          AND n.floor = dap.floor AND n.area_id IN (dap.from_area, dap.to_area)
        JOIN public.routing_edges_static e ON e.floor = n.floor AND e.door_id IS NULL AND n.id IN (e.src, e.dst)
        JOIN public.routing_nodes peer ON peer.id = CASE WHEN e.src = n.id THEN e.dst ELSE e.src END
          AND peer.floor = n.floor AND peer.area_id = n.area_id
        WHERE dap.door_id = p_door_id AND dap.floor = p_floor AND public.fn_is_one_sided_access(dap)
          AND e.base_cost > 0 AND e.geom IS NOT NULL AND NOT ST_IsEmpty(e.geom)
          AND (dap.needs_review IS NOT TRUE OR e.attrs->>'one_sided_access_id' = dap.id::text)
    );
$$;

DO $migration$
DECLARE
    definition text;
    changed text;
    endpoint_filter constant text := 'dap.needs_review IS NOT TRUE';
    endpoint_access constant text := '(dap.needs_review IS NOT TRUE OR public.fn_is_one_sided_access(dap))';
    live_filter constant text := 'COALESCE\([[:space:]]*dap\.needs_review,[[:space:]]*FALSE[[:space:]]*\) = FALSE';
    live_access constant text := '(COALESCE(dap.needs_review, FALSE) = FALSE OR (public.fn_is_one_sided_access(dap) AND rn.area_id IN (dap.from_area, dap.to_area)))';
    area_return constant text := '(\n[ \t]+RETURN jsonb_build_object\([[:space:]]*''ok'',[[:space:]]*(true|v_nodes))';
    door_return constant text := '(''routing_source'', ''door_access_points''[[:space:]]*\))';
    affected_areas constant text := 'ON CONFLICT (area_id) DO NOTHING;';
    area_locks constant text := $locks$
    FOR v_area_id IN SELECT area_id FROM tmp_door_affected_areas ORDER BY area_id LOOP
        PERFORM pg_advisory_xact_lock(hashtextextended('routing-area:' || v_area_id::text, 0));
    END LOOP;$locks$;
BEGIN
    SELECT pg_get_functiondef('public.fn_rebuild_graph_for_area(bigint,smallint,boolean,double precision,double precision)'::regprocedure) INTO definition;
    changed := regexp_replace(definition, area_return,
        E'\n    PERFORM public.fn_attach_one_sided_area_access(p_area_id, v_floor, p_clearance_m);\n\\1');
    IF (SELECT count(*) FROM regexp_matches(definition, area_return, 'g')) <> 1 THEN
        RAISE EXCEPTION 'Unexpected area graph builder definition';
    END IF;
    EXECUTE changed;

    SELECT pg_get_functiondef('public.fn_rebuild_graph_for_door(bigint,boolean,double precision,double precision)'::regprocedure) INTO definition;
    changed := regexp_replace(definition, 'ON CONFLICT \(area_id\) DO NOTHING;', affected_areas || area_locks);
    IF changed = definition THEN RAISE EXCEPTION 'Unexpected door graph affected areas'; END IF;
    definition := changed;
    changed := regexp_replace(definition, door_return, E'\\1 || CASE\n'
        '        WHEN v_area_ok > 0 AND v_area_failed = 0 AND v_inserted_forward_edges = 0' || E'\n'
        '          AND public.fn_one_sided_door_connected(p_door_id, v_floor)' || E'\n'
        '        THEN jsonb_build_object(''ok'', true, ''single_sided_connected'', true, ''inserted_nodes'', GREATEST(v_inserted_nodes, 1))' || E'\n'
        '        ELSE ''{}''::jsonb END');
    IF (SELECT count(*) FROM regexp_matches(definition, door_return, 'g')) <> 1 THEN
        RAISE EXCEPTION 'Unexpected door graph builder definition';
    END IF;
    EXECUTE changed;

    SELECT pg_get_functiondef('public.fn_routing_edges_live_param(timestamptz,public.gender_enum,text,smallint)'::regprocedure) INTO definition;
    IF (SELECT count(*) FROM regexp_matches(definition, live_filter, 'g')) <> 1 THEN
        RAISE EXCEPTION 'Unexpected live edge access filter';
    END IF;
    EXECUTE regexp_replace(definition, live_filter, live_access);

    SELECT pg_get_functiondef('public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,geometry,geometry,integer,bigint,bigint)'::regprocedure) INTO definition;
    IF length(definition) - length(replace(definition, endpoint_filter, '')) <> 2 * length(endpoint_filter) THEN
        RAISE EXCEPTION 'Unexpected exact endpoint access filters';
    END IF;
    EXECUTE replace(definition, endpoint_filter, endpoint_access);
END;
$migration$;

REVOKE EXECUTE ON FUNCTION public.fn_attach_one_sided_area_access(bigint,smallint,double precision) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION public.fn_one_sided_door_connected(bigint,smallint) FROM PUBLIC;
DO $grants$
DECLARE role_name text;
BEGIN
    FOR role_name IN SELECT rolname FROM pg_roles WHERE rolname IN ('gp_queue_runtime', 'gp_app_runtime') LOOP
        EXECUTE format('GRANT EXECUTE ON FUNCTION public.fn_is_one_sided_access(public.door_access_points), public.fn_attach_one_sided_area_access(bigint,smallint,double precision), public.fn_one_sided_door_connected(bigint,smallint) TO %I', role_name);
    END LOOP;
END;
$grants$;

COMMIT;
