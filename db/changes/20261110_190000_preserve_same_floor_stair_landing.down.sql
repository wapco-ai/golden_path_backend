BEGIN;

CREATE OR REPLACE FUNCTION public.fn_manual_door_placement(
    p_x double precision, p_y double precision, p_floor smallint, p_for_move boolean
) RETURNS TABLE(from_area bigint, to_area bigint, dap_geom geometry, door_geom geometry)
LANGUAGE plpgsql STABLE
AS $$
DECLARE
    click geometry := ST_SetSRID(ST_MakePoint(p_x, p_y), 32640);
    area_geom geometry;
    wall_ring geometry;
    opposite_boundary geometry;
    snap_distance double precision;
BEGIN
    SELECT a.id, a.geom INTO from_area, area_geom
    FROM public.areas a
    WHERE a.floor = p_floor
      AND (NOT p_for_move OR public.fn_area_routing_role(a.area_type, a.attrs) = 'routable')
    ORDER BY ST_Distance(ST_Boundary(a.geom), click), a.id
    LIMIT 1;

    SELECT ring.geom INTO wall_ring
    FROM ST_Dump(ST_Boundary(area_geom)) AS ring
    WHERE ST_Length(ring.geom) > 0
    ORDER BY ring.geom <-> click, ring.path
    LIMIT 1;

    IF wall_ring IS NULL THEN
        RAISE EXCEPTION USING ERRCODE = 'GP001', MESSAGE = 'No usable wall near the click';
    END IF;

    dap_geom := ST_ClosestPoint(wall_ring, click);
    snap_distance := ST_Distance(dap_geom, click);
    -- A shared wall can appear in two polygons. Reject ties only when the
    -- competing walls offer different snap points.
    IF EXISTS (
        SELECT 1
        FROM public.areas a
        CROSS JOIN LATERAL ST_DumpSegments(ST_Boundary(a.geom)) AS edge
        WHERE a.floor = p_floor
          AND (NOT p_for_move OR public.fn_area_routing_role(a.area_type, a.attrs) = 'routable')
          AND ST_DWithin(a.geom, click, snap_distance + 0.005)
          AND ST_DWithin(edge.geom, click, snap_distance + 0.005)
          AND NOT ST_DWithin(ST_ClosestPoint(edge.geom, click), dap_geom, 0.02)
    ) THEN
        RAISE EXCEPTION USING ERRCODE = 'GP001', MESSAGE = 'More than one wall is close to the click';
    END IF;

    SELECT a.id, ST_Boundary(a.geom) INTO to_area, opposite_boundary
    FROM public.areas a
    WHERE a.floor = p_floor AND a.id <> from_area
      AND (NOT p_for_move OR public.fn_area_routing_role(a.area_type, a.attrs) = 'routable')
      AND ST_DWithin(ST_Boundary(a.geom), dap_geom, 0.02)
    ORDER BY ST_Distance(ST_Boundary(a.geom), dap_geom), a.id
    LIMIT 1;

    door_geom := public.fn_door_display_on_wall(dap_geom, wall_ring, opposite_boundary);
    IF door_geom IS NULL THEN
        RAISE EXCEPTION USING ERRCODE = 'GP001', MESSAGE = 'Click is too close to a corner or there is no usable straight wall';
    END IF;

    RETURN NEXT;
END;
$$;

CREATE OR REPLACE FUNCTION public.fn_move_door_to_click(p_door_id bigint, p_x double precision, p_y double precision, p_floor smallint DEFAULT NULL::smallint)
 RETURNS TABLE(door_id bigint, door_geom geometry, door_from_area bigint, door_to_area bigint, dap_id bigint, dap_geom geometry)
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_door_floor     smallint;
    v_previous_floor smallint;
    v_area_id        bigint;
    v_door_geom      geometry;
    v_dap_geom       geometry;
    v_neighbor_area  bigint;
BEGIN
    -- 0) خواندن درب
    SELECT d.floor
    INTO v_previous_floor
    FROM doors d
    WHERE d.id = p_door_id
    FOR UPDATE;

    IF v_previous_floor IS NULL THEN
        RAISE EXCEPTION 'Door % not found', p_door_id;
    END IF;

    v_door_floor := COALESCE(p_floor, v_previous_floor);

    SELECT p.from_area, p.to_area, p.dap_geom, p.door_geom
    INTO v_area_id, v_neighbor_area, v_dap_geom, v_door_geom
    FROM public.fn_manual_door_placement(p_x, p_y, v_door_floor, true) AS p;

    -- 7) آپدیت doors
    UPDATE doors d
    SET geom      = v_door_geom,
        from_area = v_area_id,
        to_area   = v_neighbor_area,
        floor     = v_door_floor,
        attrs     = COALESCE(d.attrs, '{}'::jsonb) || jsonb_build_object(
            'source', 'manual_move',
            'moved_at', now()
        )
    WHERE d.id = p_door_id;

    -- 8) بازسازی door_access_points
    --------------------------------------------------------------------
-- CRITICAL FIX:
-- قبل از حذف DAP قدیمی، تمام edge/nodeهای وابسته پاک شود.
-- اگر اول DAP حذف شود، routing_nodes.ref_id یتیم می‌شود و rebuild بعدی
-- دیگر نمی‌تواند node قدیمی را از طریق join به DAP پیدا کند.
--------------------------------------------------------------------
WITH old_dap AS (
    SELECT dap.id
    FROM public.door_access_points dap
    WHERE dap.door_id = p_door_id
      AND dap.floor = v_previous_floor
),
old_nodes AS (
    SELECT rn.id
    FROM public.routing_nodes rn
    WHERE rn.floor = v_previous_floor
      AND rn.ref_table = 'door_access_points'
      AND rn.ref_id IN (SELECT id FROM old_dap)
),
old_edges AS (
    SELECT e.id
    FROM public.routing_edges_static e
    WHERE e.floor = v_previous_floor
      AND (
             e.src IN (SELECT id FROM old_nodes)
          OR e.dst IN (SELECT id FROM old_nodes)
          OR e.door_id = p_door_id
      )
),
del_edge_areas AS (
    DELETE FROM public.routing_edge_areas rea
    USING old_edges oe
    WHERE rea.edge_id = oe.id
    RETURNING rea.edge_id
),
del_edges AS (
    DELETE FROM public.routing_edges_static e
    USING old_edges oe
    WHERE e.id = oe.id
    RETURNING e.id
),
del_nodes AS (
    DELETE FROM public.routing_nodes rn
    USING old_nodes onodes
    WHERE rn.id = onodes.id
    RETURNING rn.id
)
DELETE FROM public.door_access_points dap
WHERE dap.door_id = p_door_id
  AND dap.floor = v_previous_floor;

    INSERT INTO door_access_points (
        door_id,
        geom,
        floor,
        from_area,
        to_area, build_method, needs_review, review_reason
    )
    VALUES (
        p_door_id,
        v_dap_geom,
        v_door_floor,
        v_area_id,
        v_neighbor_area, 'manual_boundary_snap', v_neighbor_area IS NULL,
        CASE WHEN v_neighbor_area IS NULL THEN 'one_sided_door' END
    )
    RETURNING id, geom
    INTO dap_id, dap_geom;

    -- 9) خروجی یک ردیف
    door_id        := p_door_id;
    door_geom      := v_door_geom;
    door_from_area := v_area_id;
    door_to_area   := v_neighbor_area;

    RETURN QUERY
        SELECT door_id, door_geom, door_from_area, door_to_area, dap_id, dap_geom;

    RETURN;
END;
$function$;

COMMIT;
