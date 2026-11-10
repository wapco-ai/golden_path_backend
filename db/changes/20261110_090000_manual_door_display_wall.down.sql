-- Draw manually placed doors on one local wall; PostGIS 3.2+ is required.
-- Changes the click functions and adds shared placement and display helpers.
-- Placement keeps the existing snap point, area selection and graph lifecycle.
-- Rollback restores the previous click functions before removing the helpers.
BEGIN;

CREATE OR REPLACE FUNCTION public.fn_create_door_from_click(p_x double precision, p_y double precision, p_floor smallint, p_allowed_gender gender_enum DEFAULT 'both'::gender_enum, p_is_open boolean DEFAULT true, p_modes text[] DEFAULT ARRAY['walk'::text, 'wheelchair'::text], p_bidirectional boolean DEFAULT true)
 RETURNS TABLE(door_id bigint, door_geom geometry, door_from_area bigint, door_to_area bigint, dap_id bigint, dap_geom geometry)
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_click_pt       geometry;
    v_area_id        bigint;
    v_area_geom      geometry;
    v_boundary       geometry;   -- حتماً LINESTRING یا MULTILINESTRING
    v_snap_pt        geometry;
    v_loc            double precision;
    v_len            double precision;
    v_frac           double precision;
    v_start_frac     double precision;
    v_end_frac       double precision;
    v_door_geom      geometry;
    v_dap_geom       geometry;
    v_neighbor_area  bigint;
BEGIN
    -- 1) نقطه کلیک
    v_click_pt := ST_SetSRID(ST_MakePoint(p_x, p_y), 32640);

    -- 2) نزدیک‌ترین محدوده در همان طبقه
    SELECT a.id, a.geom
    INTO v_area_id, v_area_geom
    FROM areas a
    WHERE a.floor = p_floor
    ORDER BY ST_Distance(ST_Boundary(a.geom),v_click_pt),a.id
    LIMIT 1;

    IF v_area_id IS NULL THEN
        RAISE EXCEPTION 'No area found near click (floor=%)', p_floor;
    END IF;

    -- 3) مرز: نزدیک‌ترین ring به کلیک
    SELECT ST_LineMerge(d.geom)
    INTO v_boundary
    FROM ST_Dump(ST_Boundary(v_area_geom)) AS d
    ORDER BY d.geom <-> v_click_pt
    LIMIT 1;

    IF v_boundary IS NULL OR ST_IsEmpty(v_boundary) THEN
        RAISE EXCEPTION 'Area % has empty or non-line boundary', v_area_id;
    END IF;

    IF GeometryType(v_boundary) NOT IN ('LINESTRING','MULTILINESTRING') THEN
        RAISE EXCEPTION 'Area % boundary is not a line (got %)', v_area_id, GeometryType(v_boundary);
    END IF;

    -- 4) نقطه روی مرز و segment ~2 متری
    v_snap_pt := ST_ClosestPoint(v_boundary, v_click_pt);

    v_loc := ST_LineLocatePoint(v_boundary, v_snap_pt);
    v_len := ST_Length(v_boundary);

    IF v_len <= 0 THEN
        RAISE EXCEPTION 'Area % has zero boundary length', v_area_id;
    END IF;

    v_frac := 2.0 / v_len; -- طول ~۲ متر

    v_start_frac := GREATEST(0.0, v_loc - v_frac / 2.0);
    v_end_frac   := LEAST(1.0, v_loc + v_frac / 2.0);

    v_door_geom := ST_LineSubstring(v_boundary, v_start_frac, v_end_frac);

    IF v_door_geom IS NULL OR ST_IsEmpty(v_door_geom) THEN
        v_door_geom := ST_MakeLine(
            ST_LineInterpolatePoint(v_boundary, GREATEST(0.0, v_loc - 0.001)),
            ST_LineInterpolatePoint(v_boundary, LEAST(1.0, v_loc + 0.001))
        );
    END IF;

    -- 5) نقطه‌ی وسط درب
    v_dap_geom := v_snap_pt;

    -- 6) محدوده مقابل (اگر باشد)
    SELECT a2.id
    INTO v_neighbor_area
    FROM areas a2
    WHERE a2.floor = p_floor
      AND a2.id <> v_area_id
      AND ST_DWithin(ST_Boundary(a2.geom), v_dap_geom, 0.02)
    ORDER BY ST_Distance(ST_Boundary(a2.geom), v_dap_geom),a2.id
    LIMIT 1;

    -- 7) درج در doors
    INSERT INTO doors (
        geom,
        from_area,
        to_area,
        floor,
        allowed_gender,
        is_open,
        modes,
        bidirectional,
        attrs
    )
    VALUES (
        v_door_geom,
        v_area_id,
        v_neighbor_area,
        p_floor,
        p_allowed_gender,
        p_is_open,
        p_modes,
        p_bidirectional,
        jsonb_build_object(
            'source', 'manual_click',
            'created_at', now()
        )
    )
    RETURNING id, geom, from_area, to_area
    INTO door_id, door_geom, door_from_area, door_to_area;

    -- 8) درج در door_access_points
    INSERT INTO door_access_points (
        door_id,
        geom,
        floor,
        from_area,
        to_area, build_method, needs_review, review_reason
    )
    VALUES (
        door_id,
        v_dap_geom,
        p_floor,
        v_area_id,
        v_neighbor_area, 'manual_boundary_snap', v_neighbor_area IS NULL,
        CASE WHEN v_neighbor_area IS NULL THEN 'one_sided_door' END
    )
    RETURNING id, geom
    INTO dap_id, dap_geom;

    -- 9) خروجی تابع (یک ردیف)
    RETURN QUERY
        SELECT door_id, door_geom, door_from_area, door_to_area, dap_id, dap_geom;

    RETURN;
END;
$function$;
CREATE OR REPLACE FUNCTION public.fn_move_door_to_click(p_door_id bigint, p_x double precision, p_y double precision, p_floor smallint DEFAULT NULL::smallint)
 RETURNS TABLE(door_id bigint, door_geom geometry, door_from_area bigint, door_to_area bigint, dap_id bigint, dap_geom geometry)
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_click_pt       geometry;
    v_door_floor     smallint;
    v_area_id        bigint;
    v_area_geom      geometry;
    v_boundary       geometry;
    v_snap_pt        geometry;
    v_loc            double precision;
    v_len            double precision;
    v_frac           double precision;
    v_start_frac     double precision;
    v_end_frac       double precision;
    v_door_geom      geometry;
    v_dap_geom       geometry;
    v_neighbor_area  bigint;
BEGIN
    -- 0) خواندن درب
    SELECT d.floor
    INTO v_door_floor
    FROM doors d
    WHERE d.id = p_door_id;

    IF v_door_floor IS NULL THEN
        RAISE EXCEPTION 'Door % not found', p_door_id;
    END IF;

    IF p_floor IS NOT NULL THEN
        v_door_floor := p_floor;
    END IF;

    -- 1) نقطه کلیک
    v_click_pt := ST_SetSRID(ST_MakePoint(p_x, p_y), 32640);

    -- 2) نزدیک‌ترین محدوده
    SELECT a.id, a.geom
INTO v_area_id, v_area_geom
FROM public.areas a
WHERE a.floor = v_door_floor
  AND public.fn_area_routing_role(a.area_type, a.attrs) = 'routable'
ORDER BY ST_Distance(ST_Boundary(a.geom),v_click_pt),a.id
LIMIT 1;

    IF v_area_id IS NULL THEN
        RAISE EXCEPTION 'No area found near click (floor=%)', v_door_floor;
    END IF;

    -- 3) مرز
    SELECT ST_LineMerge(d.geom)
    INTO v_boundary
    FROM ST_Dump(ST_Boundary(v_area_geom)) AS d
    ORDER BY d.geom <-> v_click_pt
    LIMIT 1;

    IF v_boundary IS NULL OR ST_IsEmpty(v_boundary) THEN
        RAISE EXCEPTION 'Area % has empty or non-line boundary', v_area_id;
    END IF;

    IF GeometryType(v_boundary) NOT IN ('LINESTRING','MULTILINESTRING') THEN
        RAISE EXCEPTION 'Area % boundary is not a line (got %)', v_area_id, GeometryType(v_boundary);
    END IF;

    -- 4) segment ~۲ متری
    v_snap_pt := ST_ClosestPoint(v_boundary, v_click_pt);

    v_loc := ST_LineLocatePoint(v_boundary, v_snap_pt);
    v_len := ST_Length(v_boundary);

    IF v_len <= 0 THEN
        RAISE EXCEPTION 'Area % has zero boundary length', v_area_id;
    END IF;

    v_frac := 2.0 / v_len;

    v_start_frac := GREATEST(0.0, v_loc - v_frac / 2.0);
    v_end_frac   := LEAST(1.0, v_loc + v_frac / 2.0);

    v_door_geom := ST_LineSubstring(v_boundary, v_start_frac, v_end_frac);

    IF v_door_geom IS NULL OR ST_IsEmpty(v_door_geom) THEN
        v_door_geom := ST_MakeLine(
            ST_LineInterpolatePoint(v_boundary, GREATEST(0.0, v_loc - 0.001)),
            ST_LineInterpolatePoint(v_boundary, LEAST(1.0, v_loc + 0.001))
        );
    END IF;

    -- 5) نقطه وسط
    v_dap_geom := v_snap_pt;

    -- 6) محدوده مقابل
    SELECT a2.id
INTO v_neighbor_area
FROM public.areas a2
WHERE a2.floor = v_door_floor
  AND a2.id <> v_area_id
  AND public.fn_area_routing_role(a2.area_type, a2.attrs) = 'routable'
  AND ST_DWithin(ST_Boundary(a2.geom), v_dap_geom, 0.02)
ORDER BY ST_Distance(ST_Boundary(a2.geom),v_dap_geom),a2.id
LIMIT 1;

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
      AND dap.floor = v_door_floor
),
old_nodes AS (
    SELECT rn.id
    FROM public.routing_nodes rn
    WHERE rn.floor = v_door_floor
      AND rn.ref_table = 'door_access_points'
      AND rn.ref_id IN (SELECT id FROM old_dap)
),
old_edges AS (
    SELECT e.id
    FROM public.routing_edges_static e
    WHERE e.floor = v_door_floor
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
  AND dap.floor = v_door_floor;

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

DROP FUNCTION public.fn_manual_door_placement(double precision, double precision, smallint, boolean);
DROP FUNCTION public.fn_door_display_on_wall(geometry, geometry, geometry);

COMMIT;
