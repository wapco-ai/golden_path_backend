--
-- PostgreSQL database dump
--

-- Dumped from database version 16.4 (Debian 16.4-1.pgdg110+2)
-- Dumped by pg_dump version 16.4 (Debian 16.4-1.pgdg110+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: postgis; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA public;


--
-- Name: EXTENSION postgis; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis IS 'PostGIS geometry and geography spatial types and functions';


--
-- Name: pgrouting; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgrouting WITH SCHEMA public;


--
-- Name: EXTENSION pgrouting; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgrouting IS 'pgRouting Extension';


--
-- Name: postgis_sfcgal; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS postgis_sfcgal WITH SCHEMA public;


--
-- Name: EXTENSION postgis_sfcgal; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION postgis_sfcgal IS 'PostGIS SFCGAL functions';


--
-- Name: area_type_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.area_type_enum AS ENUM (
    'courtyard',
    'riwaq',
    'iwan',
    'mosque',
    'elevator_area',
    'stair_area',
    'ramp_area',
    'admin_zone',
    'storage_area',
    'facility_area',
    'service_room',
    'warehouse_area',
    'technical_area',
    'functional_area'
);


--
-- Name: door_live_status_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.door_live_status_enum AS ENUM (
    'open',
    'closed_layer',
    'closed_time_restriction',
    'closed_prayer_restriction'
);


--
-- Name: feedback_status_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.feedback_status_enum AS ENUM (
    'pending',
    'approved',
    'rejected',
    'hidden'
);


--
-- Name: feedback_target_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.feedback_target_enum AS ENUM (
    'poi',
    'content',
    'route'
);


--
-- Name: gender_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.gender_enum AS ENUM (
    'male',
    'female',
    'both'
);


--
-- Name: lang_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.lang_enum AS ENUM (
    'fa',
    'ar',
    'en',
    'ur'
);


--
-- Name: location_ref; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.location_ref AS (
	entity_table text,
	entity_id bigint,
	floor smallint,
	geom public.geometry(Point,32640)
);


--
-- Name: navmesh_anchor; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.navmesh_anchor AS (
	tri_id bigint,
	point public.geometry(Point,32640),
	floor smallint
);


--
-- Name: poi_type_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.poi_type_enum AS ENUM (
    'courtyard_name',
    'elevator',
    'stair',
    'ramp',
    'cloakroom',
    'landmark',
    'toilet',
    'info_desk',
    'entrance',
    'exit',
    'prayer_room',
    'water',
    'rest_area',
    'warehouse',
    'administrative',
    'room',
    'other',
    'shoe_maintenance',
    'tea_house',
    'sahn',
    'eyvan',
    'ravaq',
    'masjed',
    'madrese',
    'khadamat',
    'elmi',
    'cemetery',
    'qrcode'
);


--
-- Name: route_segment; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.route_segment AS (
	seq integer,
	geom public.geometry(LineString,32640),
	mode text,
	floor smallint,
	distance_m numeric,
	duration_s numeric,
	meta jsonb
);


--
-- Name: route_segment_geo; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.route_segment_geo AS (
	seq integer,
	geom public.geometry(LineString,4326),
	mode text,
	floor smallint,
	distance_m numeric,
	duration_s numeric,
	meta jsonb
);


--
-- Name: target_type_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.target_type_enum AS ENUM (
    'poi',
    'door',
    'area',
    'coordinate'
);


--
-- Name: van_node_type_enum; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.van_node_type_enum AS ENUM (
    'stop',
    'junction'
);


--
-- Name: _ar_touch_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public._ar_touch_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END$$;


--
-- Name: _navicat_temp_stored_proc(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public._navicat_temp_stored_proc(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry) RETURNS TABLE(seq integer, geom public.geometry, cost numeric)
    LANGUAGE plpgsql
    AS $$
DECLARE
  -- نودهای موقت مبدا و مقصد
  v_origin_node    bigint;
  v_dest_node      bigint;

  -- area مبدأ و مقصد (اگر موجود باشد)
  v_origin_area_id bigint;
  v_dest_area_id   bigint;

  -- هندسهٔ راه‌پذیر (live)
  g_walk           geometry;

  -- برای Dijkstra
  v_curr_node      bigint;
  v_curr_cost      numeric;
  v_neighbor       bigint;
  v_edge_weight    numeric;
  v_new_cost       numeric;
  v_old_cost       numeric;
  v_infinity       numeric := 1e15;

  v_has_row        int;

  -- برای لاگ
  v_status         text;
  v_details        jsonb;
BEGIN
  --------------------------------------------------------------------
  -- 0) هندسهٔ walkable زنده
  --------------------------------------------------------------------
  SELECT fn_walkable_geom_live(
           p_now,
           p_gender,
           p_mode,
           p_floor
         )
  INTO g_walk;

  IF g_walk IS NULL OR ST_IsEmpty(g_walk) THEN
    v_status  := 'NO_WALKABLE_GEOM';
    v_details := jsonb_build_object(
      'msg',   'هیچ محدودهٔ مجازی برای حرکت در این طبقه پیدا نشد',
      'floor', p_floor
    );

    PERFORM fn_log_route_debug(
      'visibility_dbg',
      p_mode,
      p_gender,
      p_floor,
      p_origin,
      p_dest,
      NULL,
      NULL,
      NULL,
      v_status,
      v_details
    );

    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 0-الف) چک: مبدا/مقصد روی walkable باشند (به صورت کلی، نه با Covers)
  --------------------------------------------------------------------
  IF NOT ST_Intersects(g_walk, p_origin) THEN
    v_status  := 'ORIGIN_OUT_OF_WALKABLE';
    v_details := jsonb_build_object(
      'msg',   'مبدا خارج از محدودهٔ قابل عبور است',
      'floor', p_floor
    );

    PERFORM fn_log_route_debug(
      'visibility_dbg',
      p_mode,
      p_gender,
      p_floor,
      p_origin,
      p_dest,
      NULL,
      NULL,
      NULL,
      v_status,
      v_details
    );

    RETURN;
  END IF;

  IF NOT ST_Intersects(g_walk, p_dest) THEN
    v_status  := 'DEST_OUT_OF_WALKABLE';
    v_details := jsonb_build_object(
      'msg',   'مقصد خارج از محدودهٔ قابل عبور است',
      'floor', p_floor
    );

    PERFORM fn_log_route_debug(
      'visibility_dbg',
      p_mode,
      p_gender,
      p_floor,
      p_origin,
      p_dest,
      NULL,
      NULL,
      NULL,
      v_status,
      v_details
    );

    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 1) پیدا کردن area مبدأ و مقصد (اگر شد)
  --------------------------------------------------------------------
  SELECT a.id
  INTO v_origin_area_id
  FROM areas a
  WHERE a.floor = p_floor
    AND ST_Covers(a.geom, p_origin)
  ORDER BY ST_Distance(a.geom, p_origin)
  LIMIT 1;

  SELECT a.id
  INTO v_dest_area_id
  FROM areas a
  WHERE a.floor = p_floor
    AND ST_Covers(a.geom, p_dest)
  ORDER BY ST_Distance(a.geom, p_dest)
  LIMIT 1;

  --------------------------------------------------------------------
  -- 2) ایجاد نودهای موقتی برای مبدأ و مقصد در routing_nodes
  --------------------------------------------------------------------
  INSERT INTO routing_nodes (floor, area_id, geom, kind)
  VALUES (p_floor, v_origin_area_id, p_origin, 'temp_origin')
  RETURNING id INTO v_origin_node;

  INSERT INTO routing_nodes (floor, area_id, geom, kind)
  VALUES (p_floor, v_dest_area_id, p_dest, 'temp_dest')
  RETURNING id INTO v_dest_node;

  --------------------------------------------------------------------
  -- 3) ساخت گراف موقت (tmp_edges) از گراف لایو
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_edges;
  CREATE TEMP TABLE tmp_edges (
    src    bigint,
    dst    bigint,
    weight numeric
  ) ON COMMIT DROP;

  -- 3-الف) یال‌های لایو (براساس درها و موانع موقت)
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT
    e.src,
    e.dst,
    e.cost::numeric  -- فرض: cost همان base_cost بعد از اعمال ضریب‌ها
  FROM routing_edges_live e
  WHERE e.floor = p_floor;

  --------------------------------------------------------------------
  -- 3-ب) اتصال مبدأ موقتی به شبکه
  --     (اول area خودش، اگر نشد، بدون فیلتر area)
  --------------------------------------------------------------------
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT
    v_origin_node AS src,
    n.id          AS dst,
    ST_Length(ST_MakeLine(p_origin, n.geom))::numeric AS weight
  FROM routing_nodes n
  WHERE n.floor = p_floor
    AND n.id NOT IN (v_origin_node, v_dest_node)
    AND ST_DWithin(p_origin, n.geom, 40)
    AND (v_origin_area_id IS NULL OR n.area_id = v_origin_area_id)
    AND ST_Covers(g_walk, n.geom);

  GET DIAGNOSTICS v_has_row = ROW_COUNT;

  IF v_has_row = 0 THEN
    -- fallback: بدون فیلتر area
    INSERT INTO tmp_edges (src, dst, weight)
    SELECT
      v_origin_node AS src,
      n.id          AS dst,
      ST_Length(ST_MakeLine(p_origin, n.geom))::numeric AS weight
    FROM routing_nodes n
    WHERE n.floor = p_floor
      AND n.id NOT IN (v_origin_node, v_dest_node)
      AND ST_DWithin(p_origin, n.geom, 40)
      AND ST_Covers(g_walk, n.geom);
  END IF;

  --------------------------------------------------------------------
  -- 3-ج) اتصال مقصد موقتی به شبکه
  --     (اول area خودش، اگر نشد، بدون فیلتر area)
  --------------------------------------------------------------------
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT
    v_dest_node AS src,
    n.id        AS dst,
    ST_Length(ST_MakeLine(p_dest, n.geom))::numeric AS weight
  FROM routing_nodes n
  WHERE n.floor = p_floor
    AND n.id NOT IN (v_origin_node, v_dest_node)
    AND ST_DWithin(p_dest, n.geom, 40)
    AND (v_dest_area_id IS NULL OR n.area_id = v_dest_area_id)
    AND ST_Covers(g_walk, n.geom);

  GET DIAGNOSTICS v_has_row = ROW_COUNT;

  IF v_has_row = 0 THEN
    -- fallback: بدون فیلتر area
    INSERT INTO tmp_edges (src, dst, weight)
    SELECT
      v_dest_node AS src,
      n.id        AS dst,
      ST_Length(ST_MakeLine(p_dest, n.geom))::numeric AS weight
    FROM routing_nodes n
    WHERE n.floor = p_floor
      AND n.id NOT IN (v_origin_node, v_dest_node)
      AND ST_DWithin(p_dest, n.geom, 40)
      AND ST_Covers(g_walk, n.geom);
  END IF;

  --------------------------------------------------------------------
  -- 3-د) دوطرفه کردن یال‌ها (برای سادگی: همهٔ یال‌ها را برعکس هم می‌کنیم)
  --------------------------------------------------------------------
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT
    e.dst,
    e.src,
    e.weight
  FROM tmp_edges e;

  --------------------------------------------------------------------
  -- 4) جدول Dijkstra (از مبدأ به سمت مقصد)
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_dist;
  CREATE TEMP TABLE tmp_dist (
    node    bigint PRIMARY KEY,
    cost    numeric,
    prev    bigint,
    visited boolean DEFAULT false
  ) ON COMMIT DROP;

  INSERT INTO tmp_dist (node, cost, prev, visited)
  SELECT
    n.node,
    CASE WHEN n.node = v_origin_node THEN 0::numeric ELSE v_infinity END AS cost,
    NULL::bigint AS prev,
    false        AS visited
  FROM (
    SELECT src AS node FROM tmp_edges
    UNION
    SELECT dst AS node FROM tmp_edges
  ) AS n;

  --------------------------------------------------------------------
  -- 5) حلقهٔ اصلی Dijkstra
  --------------------------------------------------------------------
  WHILE EXISTS (SELECT 1 FROM tmp_dist WHERE NOT visited) LOOP
    -- نود با کمترین cost که هنوز بازدید نشده
    SELECT d.node, d.cost
    INTO v_curr_node, v_curr_cost
    FROM tmp_dist d
    WHERE NOT d.visited
    ORDER BY d.cost
    LIMIT 1;

    -- اگر همهٔ نودهای باقی‌مانده بی‌نهایت هستند، دیگر مسیر بهتری وجود ندارد
    IF v_curr_node IS NULL OR v_curr_cost >= v_infinity THEN
      EXIT;
    END IF;

    -- اگر به مقصد رسیدیم، می‌توانیم متوقف شویم
    IF v_curr_node = v_dest_node THEN
      EXIT;
    END IF;

    UPDATE tmp_dist
    SET visited = true
    WHERE node = v_curr_node;

    -- Relax کردن همسایه‌ها
    FOR v_neighbor, v_edge_weight IN
      SELECT e.dst, e.weight
      FROM tmp_edges e
      WHERE e.src = v_curr_node
    LOOP
      SELECT cost
      INTO v_old_cost
      FROM tmp_dist
      WHERE node = v_neighbor;

      v_new_cost := v_curr_cost + v_edge_weight;

      IF v_new_cost < v_old_cost THEN
        UPDATE tmp_dist
        SET cost = v_new_cost,
            prev = v_curr_node
        WHERE node = v_neighbor;
      END IF;
    END LOOP;
  END LOOP;

  --------------------------------------------------------------------
  -- 6) اگر هزینهٔ مقصد هنوز بی‌نهایت است → واقعاً مسیری نیست
  --------------------------------------------------------------------
  IF (SELECT d.cost FROM tmp_dist d WHERE d.node = v_dest_node) >= v_infinity THEN
    v_status := 'NO_PATH';

    v_details :=
      jsonb_build_object(
        'origin_node',  v_origin_node,
        'dest_node',    v_dest_node,
        'msg',          'هیچ مسیر قابل دسترسی بین مبدا و مقصد یافت نشد',
        'dist_origin',  (SELECT d.cost FROM tmp_dist d WHERE d.node = v_origin_node),
        'dist_dest',    (SELECT d.cost FROM tmp_dist d WHERE d.node = v_dest_node),
        'nodes_count',  (SELECT count(*) FROM tmp_dist),
        'edges_count',  (SELECT count(*) FROM tmp_edges)
      );

    PERFORM fn_log_route_debug(
      'visibility_dbg',
      p_mode,
      p_gender,
      p_floor,
      p_origin,
      p_dest,
      v_origin_node,
      v_dest_node,
      NULL,
      v_status,
      v_details
    );

    -- پاک کردن نودهای موقتی
    DELETE FROM routing_nodes WHERE id IN (v_origin_node, v_dest_node);

    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 7) بازسازی مسیر از مقصد به مبدأ و برعکس‌کردن ترتیب
  --------------------------------------------------------------------
  WITH RECURSIVE path_nodes AS (
    SELECT
      v_dest_node::bigint AS node,
      0::integer          AS ord
    UNION ALL
    SELECT
      d.prev AS node,
      p.ord + 1
    FROM tmp_dist d
    JOIN path_nodes p ON p.node = d.node
    WHERE d.prev IS NOT NULL
  ),
  ordered_path AS (
    -- برعکس کردن ترتیب تا از مبدأ به مقصد باشد
    SELECT
      rn.geom,
      (max(ord) OVER ()) - ord AS ord
    FROM path_nodes pn
    JOIN routing_nodes rn
      ON rn.id = pn.node
  ),
  t AS (
    SELECT geom, ord
    FROM ordered_path
    ORDER BY ord
  )
  SELECT
    1::integer AS seq,
    ST_MakeLine(geom ORDER BY ord)::geometry(LineString, 32640) AS geom,
    (SELECT d.cost FROM tmp_dist d WHERE d.node = v_dest_node)   AS cost
  INTO
    seq, geom, cost;

  --------------------------------------------------------------------
  -- 8) ثبت لاگ موفق
  --------------------------------------------------------------------
  v_status :=
    'OK';

  v_details :=
    jsonb_build_object(
      'origin_node', v_origin_node,
      'dest_node',   v_dest_node,
      'distance',    cost,
      'nodes_count', (SELECT count(*) FROM tmp_dist),
      'edges_count', (SELECT count(*) FROM tmp_edges)
    );

  PERFORM fn_log_route_debug(
    'visibility_dbg',
    p_mode,
    p_gender,
    p_floor,
    p_origin,
    p_dest,
    v_origin_node,
    v_dest_node,
    v_dest_node,  -- last_node
    v_status,
    v_details
  );

  --------------------------------------------------------------------
  -- 9) پاک کردن نودهای موقتی
  --------------------------------------------------------------------
  DELETE FROM routing_nodes WHERE id IN (v_origin_node, v_dest_node);

  RETURN NEXT;
END;
$$;


--
-- Name: _navicat_temp_stored_proc(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry, public.lang_enum, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public._navicat_temp_stored_proc(p_ts timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry, p_lang public.lang_enum, p_max_alternatives integer DEFAULT 2) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_path_count  integer;
  v_main_geom   geometry(LineString, 32640);
  v_main_json   jsonb;
  v_alts        jsonb := '[]'::jsonb;

  v_rec         RECORD;
BEGIN
  --------------------------------------------------------------------
  -- 1) محاسبه مسیرها با visibility + pgr_ksp
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_paths_vis;

  CREATE TEMP TABLE tmp_paths_vis AS
  SELECT *
  FROM public.fn_route_walk_visibility_k(
         p_ts::timestamptz,
         p_gender::gender_enum,
         p_mode::text,
         p_floor::smallint,
         p_origin::geometry,
         p_dest::geometry,
         (p_max_alternatives + 1)::integer   -- حداکثر 1 اصلی + N جایگزین
       );

  --------------------------------------------------------------------
  -- 2) اگر هیچ مسیری نبود
  --------------------------------------------------------------------
  SELECT COUNT(*) INTO v_path_count FROM tmp_paths_vis;

  IF v_path_count = 0 THEN
    RETURN jsonb_build_object(
      'ok', false,
      'message', 'no_path'
    );
  END IF;

  --------------------------------------------------------------------
  -- 3) اولین مسیر = main
  --------------------------------------------------------------------
  SELECT * INTO v_rec
  FROM tmp_paths_vis
  ORDER BY path_rank
  LIMIT 1;

  v_main_geom := v_rec.geom;

  v_main_json := jsonb_build_object(
    'ok', true,
    'mode', p_mode,
    'gender', p_gender,
    'floor', p_floor,
    'cost', v_rec.cost,
    'geom', ST_AsGeoJSON(v_main_geom)::jsonb
  );

  --------------------------------------------------------------------
  -- 4) alternatives
  --------------------------------------------------------------------
  FOR v_rec IN
    SELECT *
    FROM tmp_paths_vis
    WHERE path_rank > 1
    ORDER BY path_rank
    LIMIT p_max_alternatives
  LOOP
    v_alts := v_alts || jsonb_build_object(
      'rank', v_rec.path_rank,
      'cost', v_rec.cost,
      'geom', ST_AsGeoJSON(v_rec.geom)::jsonb
    );
  END LOOP;

  --------------------------------------------------------------------
  -- 5) اضافه کردن alternatives به main
  --------------------------------------------------------------------
  v_main_json :=
    v_main_json || jsonb_build_object(
      'alternatives', v_alts
    );

  RETURN v_main_json;
END;
$$;


--
-- Name: _van_edge_no_self_loop(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public._van_edge_no_self_loop() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF NEW.src = NEW.dst THEN
    RAISE EXCEPTION 'van_edges: src and dst cannot be equal';
  END IF;
  RETURN NEW;
END$$;


--
-- Name: fn_admin_restrictions_active(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_admin_restrictions_active(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS TABLE(id bigint, target_table text, target_id bigint, geom public.geometry, restrict_type text, penalty_w numeric, floor smallint)
    LANGUAGE sql STABLE
    AS $$
    SELECT
        ar.id,
        ar.target_table,
        ar.target_id,
        ar.geom,
        ar.restrict_type,
        ar.penalty_w,
        ar.floor
    FROM public.admin_restrictions ar
    WHERE ar.is_active = TRUE

      AND public.fn_ar_time_active(
          ar.starts_at,
          ar.ends_at,
          p_now
      )

      AND (
          -- محدودیت عمومی برای هر دو جنس
          ar.gender = 'both'::gender_enum

          -- محدودیت مختص جنس کاربر
          OR ar.gender = p_gender

          -- p_gender=both در UI یعنی خانواده.
          -- خانواده شامل زن و مرد است، بنابراین هر محدودیت
          -- male/female نیز باید روی خانواده اعمال شود.
          OR p_gender = 'both'::gender_enum
      )

      AND p_mode = ANY(ar.modes)

      AND (
          ar.floor IS NULL
          OR ar.floor = p_floor
      );
$$;


--
-- Name: fn_allowed_areas(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_allowed_areas(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS TABLE(id bigint, geom public.geometry, area_type public.area_type_enum, floor smallint, allowed_gender public.gender_enum, is_closed boolean, weight_open_space numeric, attrs jsonb, is_allowed boolean, admin_penalty_w numeric)
    LANGUAGE sql STABLE
    AS $$

WITH blocked_raw AS (

    SELECT
        ba.area_id,
        ba.restrict_type,
        ba.penalty_w

    FROM public.fn_blocked_areas_from_admin(
        p_now,
        p_gender,
        p_mode,
        p_floor
    ) ba
),

blocked AS (

    SELECT
        area_id,

        CASE
            WHEN bool_or(restrict_type = 'close')
                THEN 'close'

            ELSE MIN(restrict_type)
        END AS restrict_type,

        MAX(
            COALESCE(penalty_w, 0.0)
        ) AS penalty_w

    FROM blocked_raw

    GROUP BY area_id
)

SELECT
    a.id,
    a.geom,
    a.area_type,
    a.floor,
    a.allowed_gender,
    a.is_closed,
    a.weight_open_space,
    a.attrs,

    CASE

        --------------------------------------------------------------
        -- area اصولاً routable نیست
        --------------------------------------------------------------
        WHEN public.fn_area_routing_role(
                 a.area_type,
                 a.attrs
             ) <> 'routable'
            THEN FALSE


        --------------------------------------------------------------
        -- ویلچر از پله عبور نکند
        --------------------------------------------------------------
        WHEN p_mode = 'wheelchair'
             AND a.area_type::text = 'stair_area'
            THEN FALSE


        --------------------------------------------------------------
        -- محدوده بدون DAP قابل استفاده در گراف نیست
        --------------------------------------------------------------
        WHEN COALESCE(ds.door_cnt, 0) = 0
            THEN FALSE


        --------------------------------------------------------------
        -- محدودیت زمان / نماز / admin از fn_entity_access
        --------------------------------------------------------------
        WHEN COALESCE(acc.allowed, TRUE) = FALSE
            THEN FALSE


        --------------------------------------------------------------
        -- بسته بودن دائمی area
        --------------------------------------------------------------
        WHEN a.is_closed = TRUE
            THEN FALSE


        --------------------------------------------------------------
        -- Admin close
        --------------------------------------------------------------
        WHEN b.restrict_type = 'close'
            THEN FALSE


        ELSE TRUE

    END AS is_allowed,

    COALESCE(
        b.penalty_w,
        0.0
    ) AS admin_penalty_w

FROM public.areas a

LEFT JOIN blocked b
       ON b.area_id = a.id

LEFT JOIN public.mv_area_door_stats ds
       ON ds.area_id = a.id

LEFT JOIN LATERAL (

    SELECT
        ea.allowed

    FROM public.fn_entity_access(
        'areas',
        a.id,
        p_now,
        p_gender,
        p_mode,
        p_floor,

        -- area.geom مجاز است
        ST_PointOnSurface(a.geom)
    ) ea

    LIMIT 1

) acc ON TRUE

WHERE
    a.floor = p_floor

    AND (

        --------------------------------------------------------------
        -- family
        --------------------------------------------------------------
        (
            p_gender = 'both'::gender_enum
            AND a.allowed_gender = 'both'::gender_enum
        )

        OR

        --------------------------------------------------------------
        -- male / female
        --------------------------------------------------------------
        (
            p_gender IN (
                'male'::gender_enum,
                'female'::gender_enum
            )

            AND (
                a.allowed_gender = 'both'::gender_enum
                OR a.allowed_gender = p_gender
            )
        )
    );

$$;


--
-- Name: fn_allowed_doors(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_allowed_doors(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS TABLE(id bigint, geom public.geometry, from_area bigint, to_area bigint, floor smallint, allowed_gender public.gender_enum, is_open boolean, modes text[], bidirectional boolean, attrs jsonb, is_allowed boolean, admin_penalty_w numeric)
    LANGUAGE sql STABLE
    AS $$

WITH blocked_raw AS (

    SELECT
        bd.door_id,
        bd.restrict_type,
        bd.penalty_w

    FROM public.fn_blocked_doors_from_admin(
        p_now,
        p_gender,
        p_mode,
        p_floor
    ) bd
),

blocked AS (

    SELECT
        door_id,

        CASE
            WHEN bool_or(restrict_type = 'close')
                THEN 'close'
            ELSE MIN(restrict_type)
        END AS restrict_type,

        MAX(
            COALESCE(penalty_w, 0.0)
        ) AS penalty_w

    FROM blocked_raw

    GROUP BY door_id
),

/*
 * نقطه routing هر درب.
 *
 * مهم:
 * هیچ استفاده‌ای از doors.geom برای routing نمی‌شود.
 */
door_point AS (

    SELECT DISTINCT ON (dap.door_id)

        dap.door_id,
        dap.geom

    FROM public.door_access_points dap

    WHERE
        dap.floor = p_floor

        AND COALESCE(
            dap.needs_review,
            FALSE
        ) = FALSE

    ORDER BY
        dap.door_id,
        dap.id
)

SELECT

    d.id,

    /*
     * فقط برای حفظ قرارداد قدیمی تابع برگردانده می‌شود.
     * موتور routing از این geom استفاده نمی‌کند.
     */
    d.geom,

    d.from_area,
    d.to_area,
    d.floor,
    d.allowed_gender,

    --------------------------------------------------------------
    -- وضعیت واقعی زنده درب
    --------------------------------------------------------------
    COALESCE(
        dsl.is_open,
        d.is_open
    ) AS is_open,

    d.modes,
    d.bidirectional,
    d.attrs,

    --------------------------------------------------------------
    -- دسترسی نهایی
    --------------------------------------------------------------
    CASE

        WHEN COALESCE(
                 acc.allowed,
                 TRUE
             ) = FALSE
            THEN FALSE

        WHEN b.restrict_type = 'close'
            THEN FALSE

        WHEN COALESCE(
                 dsl.is_open,
                 d.is_open
             ) = FALSE
            THEN FALSE

        ELSE TRUE

    END AS is_allowed,

    COALESCE(
        b.penalty_w,
        0.0
    ) AS admin_penalty_w

FROM public.doors d

LEFT JOIN public.door_status_live dsl
       ON dsl.door_id = d.id
      AND dsl.mode = 'normal'

LEFT JOIN blocked b
       ON b.door_id = d.id

LEFT JOIN door_point dp
       ON dp.door_id = d.id

LEFT JOIN LATERAL (

    SELECT
        ea.allowed

    FROM public.fn_entity_access(
        'doors',
        d.id,
        p_now,
        p_gender,
        p_mode,
        p_floor,

        -- فقط DAP
        dp.geom
    ) ea

    LIMIT 1

) acc ON TRUE

WHERE

    d.floor = p_floor

    --------------------------------------------------------------
    -- transport mode
    --------------------------------------------------------------
    AND p_mode = ANY(d.modes)

    --------------------------------------------------------------
    -- Gender
    --------------------------------------------------------------
    AND (

        -- خانوادگی فقط از درب مشترک
        (
            p_gender = 'both'::gender_enum
            AND d.allowed_gender = 'both'::gender_enum
        )

        OR

        -- مرد/زن:
        -- درب مخصوص همان جنس یا درب مشترک
        (
            p_gender IN (
                'male'::gender_enum,
                'female'::gender_enum
            )

            AND (
                d.allowed_gender = 'both'::gender_enum
                OR d.allowed_gender = p_gender
            )
        )
    );

$$;


--
-- Name: fn_ar_time_active(timestamp with time zone, timestamp with time zone, timestamp with time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_ar_time_active(p_starts_at timestamp with time zone, p_ends_at timestamp with time zone, p_now timestamp with time zone) RETURNS boolean
    LANGUAGE sql
    AS $$
  SELECT (p_now >= p_starts_at) AND (p_ends_at IS NULL OR p_now <= p_ends_at);
$$;


--
-- Name: fn_area_doors_clockwise(public.geometry, smallint, public.lang_enum); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_area_doors_clockwise(p_point public.geometry, p_floor smallint, p_lang public.lang_enum DEFAULT 'fa'::public.lang_enum) RETURNS TABLE(area_id bigint, area_name text, area_geom public.geometry, door_no integer, door_id bigint, access_id bigint, door_geom public.geometry, from_area_id bigint, to_area_id bigint, from_area_name text, to_area_name text, other_area_id bigint, other_area_name text, door_label text)
    LANGUAGE plpgsql
    AS $$
BEGIN
  RETURN QUERY
  WITH area_sel AS (
    --------------------------------------------------------------------
    -- 1) انتخاب محدوده‌ای که نقطه را پوشش می‌دهد (یا با آن متقاطع است)
    --    اگر چندتا بود، کوچک‌ترین مساحت انتخاب می‌شود.
    --------------------------------------------------------------------
    SELECT
      a.id,
      a.geom,
      fn_i18n_label('areas', a.id, 'name', p_lang, 'fa') AS name,
      ST_Centroid(a.geom) AS center
    FROM areas a
    WHERE a.floor = p_floor
      AND ST_Intersects(a.geom, p_point)
    ORDER BY ST_Area(a.geom) ASC
    LIMIT 1
  ),

  doors_raw AS (
    --------------------------------------------------------------------
    -- 2) درب‌های متصل به این محدوده از door_access_points
    --------------------------------------------------------------------
    SELECT
      dap.id        AS access_id,
      dap.door_id,
      dap.geom,
      dap.floor,
      dap.from_area,
      dap.to_area,
      asel.id       AS area_id,
      asel.geom     AS area_geom,
      asel.name     AS area_name,
      asel.center,
      ST_Azimuth(asel.center, dap.geom) AS az
    FROM door_access_points dap
    JOIN area_sel asel
      ON dap.floor = p_floor
     AND (dap.from_area = asel.id OR dap.to_area = asel.id)
  ),

  doors_angle AS (
    --------------------------------------------------------------------
    -- 3) محاسبهٔ زاویه نسبت به شمال‌غرب محدوده
    --------------------------------------------------------------------
    SELECT
      dr.*,
      CASE
        WHEN dr.az >= 7 * pi() / 4.0
          THEN dr.az - 7 * pi() / 4.0       -- از NW تا 2π
        ELSE dr.az + 2 * pi() - 7 * pi() / 4.0  -- از 0 تا NW
      END AS angle_from_nw
    FROM doors_raw dr
  ),

  ordered AS (
    --------------------------------------------------------------------
    -- 4) شماره‌گذاری درب‌ها به صورت ساعت‌گرد از شمال‌غرب
    --------------------------------------------------------------------
    SELECT
      da.*,
      row_number() OVER (ORDER BY da.angle_from_nw) AS door_no
    FROM doors_angle da
    ORDER BY da.angle_from_nw
  )

    ----------------------------------------------------------------------
  -- 5) خروجی نهایی با برچسب «از / به»
  ----------------------------------------------------------------------
  SELECT
    o.area_id,
    o.area_name,
    o.area_geom,
    o.door_no::int AS door_no,      -- 👈 اینجا CAST اضافه شد
    o.door_id,
    o.access_id,
    o.geom AS door_geom,
    o.from_area      AS from_area_id,
    o.to_area        AS to_area_id,
    fn_i18n_label('areas', o.from_area, 'name', p_lang, 'fa') AS from_area_name,
    fn_i18n_label('areas', o.to_area, 'name', p_lang, 'fa')   AS to_area_name,

    -- محدودهٔ دیگر (آن طرف درب)
    CASE
      WHEN o.from_area = o.area_id THEN o.to_area
      ELSE o.from_area
    END AS other_area_id,

    CASE
      WHEN o.from_area = o.area_id THEN
        fn_i18n_label('areas', o.to_area, 'name', p_lang, 'fa')
      ELSE
        fn_i18n_label('areas', o.from_area, 'name', p_lang, 'fa')
    END AS other_area_name,

    -- متن نهایی: «درب شماره N از [محدوده فعلی] به [محدوده دیگر]»
    CASE
      WHEN o.from_area = o.area_id THEN
        format(
          E'درب شماره %s از %s به %s',
          o.door_no,
          o.area_name,
          COALESCE(
            fn_i18n_label('areas', o.to_area, 'name', p_lang, 'fa'),
            'نامشخص'
          )
        )
      WHEN o.to_area = o.area_id THEN
        format(
          E'درب شماره %s از %s به %s',
          o.door_no,
          o.area_name,
          COALESCE(
            fn_i18n_label('areas', o.from_area, 'name', p_lang, 'fa'),
            'نامشخص'
          )
        )
      ELSE
        format(E'درب شماره %s', o.door_no)
    END AS door_label
  FROM ordered o;
END;
$$;


--
-- Name: fn_area_doors_clockwise_json(public.geometry, smallint, public.lang_enum); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_area_doors_clockwise_json(p_point public.geometry, p_floor smallint, p_lang public.lang_enum DEFAULT 'fa'::public.lang_enum) RETURNS jsonb
    LANGUAGE sql
    AS $$
WITH doors AS (
  -- خروجی کامل از تابع قبلی
  SELECT *
  FROM fn_area_doors_clockwise(p_point, p_floor, p_lang)
),

area_info AS (
  -- فقط یک area (همونی که انتخاب شده)
  SELECT DISTINCT
    area_id,
    area_name,
    area_geom,
    ST_Transform(area_geom, 4326)          AS geom4326,
    ST_Transform(ST_Centroid(area_geom), 4326) AS centroid4326,
    ST_Area(area_geom)                     AS area_m2
  FROM doors
),

area_json AS (
  SELECT
    jsonb_build_object(
      'id',        area_id,
      'name',      area_name,
      'floor',     p_floor,
      'areaM2',    area_m2,
      'coord32640',
        jsonb_build_array(
          ST_X(ST_Centroid(area_geom)),
          ST_Y(ST_Centroid(area_geom))
        ),
      'coord4326',
        jsonb_build_array(
          ST_X(centroid4326),
          ST_Y(centroid4326)
        ),
      'bbox4326',
        jsonb_build_array(
          ST_XMin(geom4326),
          ST_YMin(geom4326),
          ST_XMax(geom4326),
          ST_YMax(geom4326)
        ),
      -- هندسه کامل محدوده برای رسم روی نقشه (GeoJSON 4326)
      'geometry',
        ST_AsGeoJSON(geom4326)::jsonb
    ) AS area
  FROM area_info
),

doors_json AS (
  SELECT
    jsonb_agg(
      jsonb_build_object(
        'doorNo',       door_no,
        'doorId',       door_id,
        'accessId',     access_id,
        'label',        door_label,
        'fromAreaId',   from_area_id,
        'fromAreaName', from_area_name,
        'toAreaId',     to_area_id,
        'toAreaName',   to_area_name,
        'otherAreaId',  other_area_id,
        'otherAreaName',other_area_name,
        'coord32640',
          jsonb_build_array(
            ST_X(door_geom),
            ST_Y(door_geom)
          ),
        'coord4326',
          jsonb_build_array(
            ST_X(ST_Transform(door_geom, 4326)),
            ST_Y(ST_Transform(door_geom, 4326))
          )
      )
      ORDER BY door_no
    ) AS doors
  FROM doors
)

SELECT jsonb_build_object(
  'area',  (SELECT area  FROM area_json),
  'doors', COALESCE((SELECT doors FROM doors_json), '[]'::jsonb)
);
$$;


--
-- Name: fn_area_routing_role(public.area_type_enum, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_area_routing_role(p_area_type public.area_type_enum, p_attrs jsonb DEFAULT '{}'::jsonb) RETURNS text
    LANGUAGE sql IMMUTABLE
    AS $$
  SELECT CASE
    ------------------------------------------------------------------
    -- override اختیاری برای موارد خاص، بدون افزودن ستون جدید
    ------------------------------------------------------------------
    WHEN COALESCE(p_attrs, '{}'::jsonb) ? 'routing_role'
      THEN COALESCE(p_attrs->>'routing_role', 'routable')

    ------------------------------------------------------------------
    -- محدوده‌های شفاف: در گراف به‌عنوان area مستقل نیایند
    ------------------------------------------------------------------
    WHEN p_area_type::text IN (
      'stair_area',
      'ramp_area',
      'elevator_area'
    )
      THEN 'transparent'

    ------------------------------------------------------------------
    -- محدوده‌های مقصد تا دم درب؛ نام‌ها را با enum واقعی خودت هماهنگ کن
    ------------------------------------------------------------------
    WHEN p_area_type::text IN (
      'storage_area',
      'warehouse_area',
      'facility_area',
      'technical_area',
      'service_room',
      'functional_area',
      'admin_zone'
    )
      THEN 'doorstep_only'

    ------------------------------------------------------------------
    -- پیش‌فرض: محدوده قابل عبور
    ------------------------------------------------------------------
    ELSE 'routable'
  END;
$$;


--
-- Name: fn_areas_mvt(integer, integer, integer, smallint, public.lang_enum); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_areas_mvt(z integer, x integer, y integer, p_floor smallint DEFAULT NULL::smallint, p_lang public.lang_enum DEFAULT 'en'::public.lang_enum) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857   geometry;  -- bbox تایل در 3857
  tile_bbox_32640  geometry;  -- همان bbox در SRID داده‌ها
BEGIN
  -- bbox تایل در WebMercator
  tile_bbox_3857 := ST_TileEnvelope(z, x, y);

  -- تبدیل bbox به سیستم مختصات داده‌ها (32640)
  tile_bbox_32640 := ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT ST_AsMVT(t, 'areas', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(a.geom, 3857),  -- تبدیل داده‌ها به 3857 برای MVT
          tile_bbox_3857,              -- bbox در همان 3857
          4096,
          64,
          true
        ) AS geom,
        a.id,
        a.area_type,
        a.floor,
        a.allowed_gender,
        a.is_closed,
        a.weight_open_space,
        a.attrs,
				i.txt AS label,
				p_lang::text AS dbg_lang,
p_floor::int AS dbg_floor
      FROM areas a
			LEFT JOIN i18n_texts i
				ON i.entity_table = 'areas'
				AND i.entity_id = a.id
				AND i.field = 'name'              -- ✅ خیلی مهم
       AND i.lang = p_lang 
      WHERE
        (p_floor IS NULL OR a.floor = p_floor)
        -- *** اینجا فیلتر مکانی در SRID درست (32640) انجام می‌شود ***
        AND a.geom && tile_bbox_32640
        AND ST_Intersects(a.geom, tile_bbox_32640)
    ) AS t
    WHERE geom IS NOT NULL
  );
END;
$$;


--
-- Name: fn_blocked_areas_from_admin(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_blocked_areas_from_admin(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS TABLE(area_id bigint, restrict_type text, penalty_w numeric)
    LANGUAGE sql
    AS $$
  WITH act AS (
    SELECT * FROM fn_admin_restrictions_active(p_now, p_gender, p_mode, p_floor)
  ),
  direct AS (
    SELECT a2.id AS area_id, a.restrict_type, a.penalty_w
    FROM act a
    JOIN areas a2 ON a.target_table = 'areas' AND a.target_id = a2.id
  ),
  spatial AS (
    SELECT a2.id AS area_id, a.restrict_type, a.penalty_w
    FROM act a
    JOIN areas a2 ON (a.target_table IS NULL OR a.target_table = 'areas')
    WHERE a.geom IS NOT NULL
      AND a2.floor = p_floor
      AND ST_Intersects(a2.geom, a.geom)
  )
  SELECT area_id, restrict_type, penalty_w FROM direct
  UNION
  SELECT area_id, restrict_type, penalty_w FROM spatial;
$$;


--
-- Name: fn_blocked_doors_from_admin(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_blocked_doors_from_admin(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS TABLE(door_id bigint, restrict_type text, penalty_w numeric)
    LANGUAGE sql STABLE
    AS $$

WITH act AS (
    SELECT *
    FROM public.fn_admin_restrictions_active(
        p_now,
        p_gender,
        p_mode,
        p_floor
    )
),

/*
 * محدودیت مستقیمی که با target_table='doors'
 * و target_id ثبت شده است.
 *
 * doors فقط برای شناسه/metadata استفاده می‌شود.
 * هیچ استفاده‌ای از doors.geom نداریم.
 */
direct AS (
    SELECT
        d.id AS door_id,
        a.restrict_type,
        a.penalty_w
    FROM act a
    JOIN public.doors d
      ON a.target_table = 'doors'
     AND a.target_id = d.id
    WHERE d.floor = p_floor
),

/*
 * محدودیت spatial:
 *
 * تنها هندسه مجاز برای تشخیص موقعیت درب،
 * door_access_points.geom است.
 */
spatial AS (
    SELECT DISTINCT
        dap.door_id,
        a.restrict_type,
        a.penalty_w
    FROM act a
    JOIN public.door_access_points dap
      ON a.geom IS NOT NULL
     AND dap.floor = p_floor
     AND COALESCE(dap.needs_review, FALSE) = FALSE
     AND ST_Intersects(
            dap.geom,
            a.geom
         )
    WHERE
        a.target_table IS NULL
        OR a.target_table = 'doors'
)

SELECT
    door_id,
    restrict_type,
    penalty_w
FROM direct

UNION

SELECT
    door_id,
    restrict_type,
    penalty_w
FROM spatial;

$$;


--
-- Name: fn_build_door_transition_edges_from_dap(smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_build_door_transition_edges_from_dap(p_floor smallint) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_deleted integer := 0;
    v_inserted_forward integer := 0;
    v_inserted_reverse integer := 0;
    v_total integer := 0;
BEGIN
    --------------------------------------------------------------------
    -- حذف edgeهای عبور از درب در همین طبقه
    -- intra-area edgeها door_id = NULL دارند و دست نمی‌خورند.
    --------------------------------------------------------------------
    DELETE FROM public.routing_edges_static e
    WHERE e.floor = p_floor
      AND e.door_id IS NOT NULL;

    GET DIAGNOSTICS v_deleted = ROW_COUNT;

    --------------------------------------------------------------------
    -- edge اصلی:
    -- از node مربوط به dap.from_area
    -- به node مربوط به dap.to_area
    --
    -- هندسه از routing_nodes می‌آید، نه از doors.geom.
    -- door_id فقط برای rule/status/name نگهداری می‌شود.
    --------------------------------------------------------------------
    INSERT INTO public.routing_edges_static (
        floor,
        src,
        dst,
        geom,
        base_cost,
        door_id,
        attrs
    )
    SELECT
        dap.floor,
        n_from.id AS src,
        n_to.id AS dst,
        ST_MakeLine(
            n_from.geom,
            n_to.geom
        )::geometry(LineString, 32640) AS geom,
        GREATEST(
            ST_Distance(n_from.geom, n_to.geom),
            0.50
        )::numeric AS base_cost,
        dap.door_id,
        jsonb_build_object(
            'edge_type', 'door_transition',
            'source', 'door_access_points',
            'direction', 'forward',
            'access_id', dap.id,
            'door_id', dap.door_id,
            'from_area', dap.from_area,
            'to_area', dap.to_area,
            'bidirectional', COALESCE(d.bidirectional, true),
            'created_at', now()
        ) AS attrs
    FROM public.door_access_points dap
    JOIN public.doors d
      ON d.id = dap.door_id
    JOIN public.routing_nodes n_from
      ON n_from.floor = dap.floor
     AND n_from.ref_table = 'door_access_points'
     AND n_from.ref_id = dap.id
     AND n_from.area_id = dap.from_area
    JOIN public.routing_nodes n_to
      ON n_to.floor = dap.floor
     AND n_to.ref_table = 'door_access_points'
     AND n_to.ref_id = dap.id
     AND n_to.area_id = dap.to_area
    WHERE dap.floor = p_floor
      AND COALESCE(dap.needs_review, false) = false
      AND dap.from_area IS NOT NULL
      AND dap.to_area IS NOT NULL;

    GET DIAGNOSTICS v_inserted_forward = ROW_COUNT;

    --------------------------------------------------------------------
    -- edge برگشتی فقط برای درب‌های دوطرفه
    -- چون routing_edges_static ستون reverse_cost ندارد،
    -- برای directed routing باید edge معکوس هم ساخته شود.
    --------------------------------------------------------------------
    INSERT INTO public.routing_edges_static (
        floor,
        src,
        dst,
        geom,
        base_cost,
        door_id,
        attrs
    )
    SELECT
        dap.floor,
        n_to.id AS src,
        n_from.id AS dst,
        ST_MakeLine(
            n_to.geom,
            n_from.geom
        )::geometry(LineString, 32640) AS geom,
        GREATEST(
            ST_Distance(n_to.geom, n_from.geom),
            0.50
        )::numeric AS base_cost,
        dap.door_id,
        jsonb_build_object(
            'edge_type', 'door_transition',
            'source', 'door_access_points',
            'direction', 'reverse',
            'access_id', dap.id,
            'door_id', dap.door_id,
            'from_area', dap.to_area,
            'to_area', dap.from_area,
            'bidirectional', true,
            'created_at', now()
        ) AS attrs
    FROM public.door_access_points dap
    JOIN public.doors d
      ON d.id = dap.door_id
    JOIN public.routing_nodes n_from
      ON n_from.floor = dap.floor
     AND n_from.ref_table = 'door_access_points'
     AND n_from.ref_id = dap.id
     AND n_from.area_id = dap.from_area
    JOIN public.routing_nodes n_to
      ON n_to.floor = dap.floor
     AND n_to.ref_table = 'door_access_points'
     AND n_to.ref_id = dap.id
     AND n_to.area_id = dap.to_area
    WHERE dap.floor = p_floor
      AND COALESCE(dap.needs_review, false) = false
      AND dap.from_area IS NOT NULL
      AND dap.to_area IS NOT NULL
      AND COALESCE(d.bidirectional, true) = true;

    GET DIAGNOSTICS v_inserted_reverse = ROW_COUNT;

    SELECT count(*)::integer
    INTO v_total
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor
      AND e.door_id IS NOT NULL;

    RETURN jsonb_build_object(
        'ok', true,
        'floor', p_floor,
        'deleted_old_door_edges', v_deleted,
        'inserted_forward', v_inserted_forward,
        'inserted_reverse', v_inserted_reverse,
        'total_door_transition_edges', v_total,
        'source', 'door_access_points'
    );
END;
$$;


--
-- Name: fn_build_intra_area_edge_geom(bigint, smallint, public.geometry, public.geometry, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_build_intra_area_edge_geom(p_area_id bigint, p_floor smallint, p_start public.geometry, p_end public.geometry, p_tol_m double precision DEFAULT 0.20) RETURNS public.geometry
    LANGUAGE plpgsql
    AS $_$
DECLARE
  v_area_geom geometry;
  v_direct geometry(LineString, 32640);
  v_start_tri bigint;
  v_end_tri bigint;
  v_sql text;
  v_mesh_line geometry(LineString, 32640);
  v_smooth geometry(LineString, 32640);
BEGIN
  IF p_area_id IS NULL OR p_start IS NULL OR p_end IS NULL THEN
    RETURN NULL;
  END IF;

  SELECT ST_MakeValid(a.geom)::geometry
  INTO v_area_geom
  FROM public.areas a
  WHERE a.id = p_area_id
    AND a.floor = p_floor;

  IF v_area_geom IS NULL THEN
    RETURN NULL;
  END IF;

  v_direct := ST_MakeLine(
    p_start::geometry(Point, 32640),
    p_end::geometry(Point, 32640)
  )::geometry(LineString, 32640);

  -- اگر خط مستقیم با سخت‌گیری جدید کاملاً قابل قبول است، همان بهترین و صاف‌ترین مسیر است
  IF public.fn_route_line_valid_inside_area(p_area_id, v_direct, p_tol_m) THEN
    RETURN v_direct;
  END IF;

  -- پیدا کردن نزدیک‌ترین سلول mesh برای شروع/پایان
  SELECT mt.id
  INTO v_start_tri
  FROM public.mesh_triangles mt
  WHERE mt.floor = p_floor
    AND mt.area_id = p_area_id
  ORDER BY
    CASE WHEN ST_Covers(ST_Buffer(mt.geom, 0.05), p_start) THEN 0 ELSE 1 END,
    mt.geom <-> p_start
  LIMIT 1;

  SELECT mt.id
  INTO v_end_tri
  FROM public.mesh_triangles mt
  WHERE mt.floor = p_floor
    AND mt.area_id = p_area_id
  ORDER BY
    CASE WHEN ST_Covers(ST_Buffer(mt.geom, 0.05), p_end) THEN 0 ELSE 1 END,
    mt.geom <-> p_end
  LIMIT 1;

  -- اگر mesh نداریم، direct را برگردان؛ در مرحله build با safety=unsafe حذف/بی‌اثر می‌شود
  IF v_start_tri IS NULL OR v_end_tri IS NULL THEN
    RETURN v_direct;
  END IF;

  IF v_start_tri = v_end_tri THEN
    SELECT ST_MakeLine(ARRAY[
      p_start::geometry(Point, 32640),
      ST_PointOnSurface(mt.geom)::geometry(Point, 32640),
      p_end::geometry(Point, 32640)
    ])::geometry(LineString, 32640)
    INTO v_mesh_line
    FROM public.mesh_triangles mt
    WHERE mt.id = v_start_tri;

    IF v_mesh_line IS NOT NULL AND NOT ST_IsEmpty(v_mesh_line) THEN
      v_smooth := public.fn_route_smooth_polyline_inside_area(p_area_id, v_mesh_line, p_tol_m);

      IF v_smooth IS NOT NULL
         AND public.fn_route_line_valid_inside_area(p_area_id, v_smooth, p_tol_m)
      THEN
        RETURN v_smooth;
      END IF;
    END IF;

    RETURN v_direct;
  END IF;

  v_sql := format(
    $q$
    SELECT
      ma.id::bigint AS id,
      ma.tri_a::bigint AS source,
      ma.tri_b::bigint AS target,
      (
        GREATEST(
          ST_Distance(ST_PointOnSurface(ta.geom), ST_PointOnSurface(tb.geom)),
          0.10
        ) * COALESCE(ma.cost_w, 1.0)
      )::float8 AS cost,
      (
        GREATEST(
          ST_Distance(ST_PointOnSurface(ta.geom), ST_PointOnSurface(tb.geom)),
          0.10
        ) * COALESCE(ma.cost_w, 1.0)
      )::float8 AS reverse_cost
    FROM public.mesh_adjacency ma
    JOIN public.mesh_triangles ta ON ta.id = ma.tri_a
    JOIN public.mesh_triangles tb ON tb.id = ma.tri_b
    WHERE ta.floor = %s
      AND tb.floor = %s
      AND ta.area_id = %s
      AND tb.area_id = %s
    $q$,
    p_floor,
    p_floor,
    p_area_id,
    p_area_id
  );

  WITH route_raw AS (
    SELECT *
    FROM pgr_dijkstra(v_sql, v_start_tri, v_end_tri, directed := false)
  ),
  route_points AS (
    SELECT
      0::bigint AS ord,
      p_start::geometry(Point, 32640) AS geom

    UNION ALL

    SELECT
      (1000 + rr.seq)::bigint AS ord,
      ST_PointOnSurface(mt.geom)::geometry(Point, 32640) AS geom
    FROM route_raw rr
    JOIN public.mesh_triangles mt
      ON mt.id = rr.node
    WHERE rr.node IS NOT NULL

    UNION ALL

    SELECT
      999999999::bigint AS ord,
      p_end::geometry(Point, 32640) AS geom
  )
  SELECT
    ST_RemoveRepeatedPoints(
      ST_MakeLine(rp.geom ORDER BY rp.ord),
      0.01
    )::geometry(LineString, 32640)
  INTO v_mesh_line
  FROM route_points rp;

  IF v_mesh_line IS NOT NULL AND NOT ST_IsEmpty(v_mesh_line) THEN
    v_smooth := public.fn_route_smooth_polyline_inside_area(p_area_id, v_mesh_line, p_tol_m);

    IF v_smooth IS NOT NULL
       AND public.fn_route_line_valid_inside_area(p_area_id, v_smooth, p_tol_m)
    THEN
      RETURN v_smooth;
    END IF;

    IF public.fn_route_line_valid_inside_area(p_area_id, v_mesh_line, p_tol_m) THEN
      RETURN v_mesh_line;
    END IF;
  END IF;

  -- فقط برای حفظ connectivity خام؛ live routing آن را با safety=unsafe حذف می‌کند
  RETURN v_direct;
END;
$_$;


--
-- Name: fn_build_route_json(public.geometry, smallint, text, public.lang_enum, text, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_build_route_json(p_geom public.geometry, p_floor smallint, p_mode text, p_lang public.lang_enum, p_source text DEFAULT 'computed'::text, p_path_edges jsonb DEFAULT '[]'::jsonb) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_distance_m numeric;
  v_estimated_min numeric;
  v_speed_mps numeric;
  v_steps jsonb := '[]'::jsonb;
  v_steps_doors jsonb := '[]'::jsonb;
  v_sahns jsonb := '[]'::jsonb;
  v_via_points jsonb := '[]'::jsonb;
  v_start_4326 geometry(Point,4326);
  v_end_4326 geometry(Point,4326);
BEGIN
  v_distance_m := ST_Length(p_geom);

  v_speed_mps :=
    CASE p_mode
      WHEN 'walk' THEN 1.2
      WHEN 'wheelchair' THEN 1.0
      WHEN 'van' THEN 5.0
      ELSE 1.2
    END;

  v_estimated_min := (v_distance_m / v_speed_mps) / 60.0;

  v_start_4326 := ST_Transform(ST_StartPoint(p_geom), 4326)::geometry(Point,4326);
  v_end_4326   := ST_Transform(ST_EndPoint(p_geom), 4326)::geometry(Point,4326);

  --------------------------------------------------------------------
  -- sahns فقط برای viaPoints بماند؛ نه برای step
  --------------------------------------------------------------------
  WITH route AS (
    SELECT p_geom AS geom
  ),
  sahn_areas AS (
    SELECT
      a.id,
      a.geom,
      COALESCE(t.txt, format('area %s', a.id)) AS name
    FROM areas a
    LEFT JOIN i18n_texts t
      ON t.entity_table = 'areas'
     AND t.entity_id = a.id
     AND t.field = 'name'
     AND t.lang = p_lang
    WHERE a.area_type = 'courtyard'
      AND a.floor = p_floor
  ),
  sahn_hits AS (
    SELECT DISTINCT ON (s.id)
      s.id,
      s.name,
      ST_LineLocatePoint(
        p_geom,
        ST_ClosestPoint(p_geom, ST_PointOnSurface(s.geom))
      ) AS pos
    FROM route r
    JOIN sahn_areas s
      ON ST_Intersects(r.geom, ST_Buffer(s.geom, -0.30))
    ORDER BY s.id, pos
  )
  SELECT COALESCE(
    jsonb_agg(
      jsonb_build_object(
        'areaId', id,
        'name', name
      )
      ORDER BY pos
    ),
    '[]'::jsonb
  )
  INTO v_sahns
  FROM sahn_hits;

  v_via_points := (
    SELECT COALESCE(
      jsonb_agg(
        jsonb_build_object(
          'type', 'sahn',
          'refType', 'area',
          'refId', (s->>'areaId')::bigint,
          'name', s->>'name'
        )
      ),
      '[]'::jsonb
    )
    FROM jsonb_array_elements(v_sahns) AS s
  );

  --------------------------------------------------------------------
  -- گام‌های درب فقط از ordered path_edges، نه از overlap هندسی
  --------------------------------------------------------------------
    --------------------------------------------------------------------
  -- گام‌های درب فقط از ordered path_edges
  -- اما مختصات و routeM از door_access_points.geom می‌آید،
  -- نه از doors.geom.
  --------------------------------------------------------------------
  WITH raw_edges AS (
    SELECT
      row_number() OVER () AS ord,
      (e->>'seq')::int AS seq,
      NULLIF(e->>'edgeId','')::bigint AS edge_id,
      NULLIF(e->>'doorId','')::bigint AS door_id,
      NULLIF(e->>'fromNode','')::bigint AS from_node,
      NULLIF(e->>'toNode','')::bigint AS to_node
    FROM jsonb_array_elements(COALESCE(p_path_edges, '[]'::jsonb)) e
  ),

  edge_data AS (
    SELECT
      re.ord,
      re.seq,
      re.edge_id,
      re.door_id,
      re.from_node,
      re.to_node,

      es.attrs,
      es.door_id AS edge_door_id,

      NULLIF(es.attrs->>'access_id', '')::bigint AS access_id,

      rn_from.area_id AS actual_from_area,
      rn_to.area_id   AS actual_to_area

    FROM raw_edges re
    JOIN public.routing_edges_static es
      ON es.id = re.edge_id
    LEFT JOIN public.routing_nodes rn_from
      ON rn_from.id = re.from_node
    LEFT JOIN public.routing_nodes rn_to
      ON rn_to.id = re.to_node
    WHERE COALESCE(es.attrs->>'edge_type', '') = 'door_transition'
       OR es.door_id IS NOT NULL
  ),

  door_edges AS (
    SELECT
      ed.ord,
      ed.seq,
      ed.edge_id,
      COALESCE(ed.edge_door_id, ed.door_id, dap.door_id) AS door_id,
      ed.access_id,

      dap.geom AS access_geom,
      dap.from_area AS dap_from_area,
      dap.to_area   AS dap_to_area,

      COALESCE(ed.actual_from_area, NULLIF(ed.attrs->>'from_area', '')::bigint, dap.from_area) AS actual_from_area,
      COALESCE(ed.actual_to_area,   NULLIF(ed.attrs->>'to_area', '')::bigint,   dap.to_area)   AS actual_to_area,

      d.modes,
      COALESCE(d.attrs, '{}'::jsonb) AS door_attrs

    FROM edge_data ed
    JOIN public.door_access_points dap
      ON dap.id = ed.access_id
    LEFT JOIN public.doors d
      ON d.id = dap.door_id
    WHERE dap.floor = p_floor
      AND dap.geom IS NOT NULL
  ),

  dedup AS (
    SELECT DISTINCT ON (access_id, actual_from_area, actual_to_area)
      *
    FROM door_edges
    ORDER BY
      access_id,
      actual_from_area,
      actual_to_area,
      ord
  ),

  ordered AS (
  SELECT
    *,
    row_number() OVER (ORDER BY ord) AS step_no,
    ST_LineLocatePoint(
      p_geom,
      ST_ClosestPoint(p_geom, access_geom)
    ) AS route_m
  FROM dedup
),

annotated AS (
  SELECT
    o.*,

    /*
      route_m بین 0 و 1 است.
      برای فاصله واقعی روی مسیر، آن را در طول مسیر ضرب می‌کنیم.
    */
    (o.route_m * v_distance_m)::double precision AS route_meter,

    LAG(o.route_m * v_distance_m) OVER (
      ORDER BY o.route_m, o.ord
    )::double precision AS prev_route_meter,

    LEAD(o.route_m * v_distance_m) OVER (
      ORDER BY o.route_m, o.ord
    )::double precision AS next_route_meter

  FROM ordered o
),

filtered AS (
  SELECT
    a.*,

    CASE
      /*
        گام خیلی نزدیک به شروع مسیر:
        معمولاً برای کاربر مزاحم است.
      */
      WHEN a.route_meter < 4.0
        THEN false

      /*
        گام خیلی نزدیک به مقصد:
        اعلام مستقل لازم ندارد.
      */
      WHEN (v_distance_m::double precision - a.route_meter) < 4.0
        THEN false

      /*
        اگر با گام قبلی کمتر از 6 متر فاصله دارد،
        به‌عنوان گام فنی/ریز در خروجی کاربر نمایش داده نشود.
      */
      WHEN a.prev_route_meter IS NOT NULL
           AND (a.route_meter - a.prev_route_meter) < 6.0
        THEN false

      ELSE true
    END AS should_show_step

  FROM annotated a
)

  SELECT COALESCE(
    jsonb_agg(
      jsonb_build_object(
        'type', 'stepPassDoor',

        'title',
          COALESCE(
            dt.txt,
            format(
              'عبور از %s',
              COALESCE(
                NULLIF(o.door_attrs->>'name', ''),
                format('درب %s', o.door_id)
              )
            )
          ),

        'coord', jsonb_build_object(
          'lon', ST_X(ST_Transform(o.access_geom, 4326)),
          'lat', ST_Y(ST_Transform(o.access_geom, 4326))
        ),

        'services', jsonb_build_object(
          'walking',     ('walk' = ANY(COALESCE(o.modes, ARRAY['walk']::text[]))),
          'wheelchair',  ('wheelchair' = ANY(COALESCE(o.modes, ARRAY[]::text[]))),
          'electricVan', ('van' = ANY(COALESCE(o.modes, ARRAY[]::text[])))
        ),

        'doorId', o.door_id,
        'accessId', o.access_id,
        'edgeId', o.edge_id,

        'routeM', o.route_m,
        'stepOrder', o.step_no,

        'fromAreaId', o.actual_from_area,
        'toAreaId', o.actual_to_area,

        'fromAreaName', COALESCE(af.txt, format('area %s', o.actual_from_area)),
        'toAreaName',   COALESCE(at.txt, format('area %s', o.actual_to_area)),

        'source', 'door_access_points'
      )
      ORDER BY o.ord
    ),
    '[]'::jsonb
  )
  INTO v_steps_doors
FROM filtered o

  LEFT JOIN public.i18n_texts dt
    ON dt.entity_table = 'doors'
   AND dt.entity_id = o.door_id
   AND dt.field = 'name'
   AND dt.lang = p_lang

  LEFT JOIN public.i18n_texts af
    ON af.entity_table = 'areas'
   AND af.entity_id = o.actual_from_area
   AND af.field = 'name'
   AND af.lang = p_lang

  LEFT JOIN public.i18n_texts at
    ON at.entity_table = 'areas'
   AND at.entity_id = o.actual_to_area
   AND at.field = 'name'
   AND at.lang = p_lang
	 
	WHERE o.should_show_step = true;
	 
	 
	 
	 
	 

  v_steps := jsonb_build_array(
    jsonb_build_object(
      'type', 'stepStart',
      'title', 'شروع حرکت',
      'coord', jsonb_build_object(
        'lon', ST_X(v_start_4326),
        'lat', ST_Y(v_start_4326)
      ),
			'routeM', 0,
'stepOrder', 0,
      'services', jsonb_build_object(
        'walking', (p_mode = 'walk'),
        'wheelchair', (p_mode = 'wheelchair'),
        'electricVan', (p_mode = 'van')
      )
    )
  );

  v_steps := v_steps || v_steps_doors;

  v_steps := v_steps || jsonb_build_array(
    jsonb_build_object(
      'type', 'stepArriveDestination',
      'title', 'رسیدن به مقصد',
      'coord', jsonb_build_object(
        'lon', ST_X(v_end_4326),
        'lat', ST_Y(v_end_4326)
      ),
			'routeM', 1,
'stepOrder', jsonb_array_length(v_steps),
      'services', jsonb_build_object(
        'walking', (p_mode = 'walk'),
        'wheelchair', (p_mode = 'wheelchair'),
        'electricVan', (p_mode = 'van')
      )
    )
  );

  -- داخل تابع public.fn_build_route_json
-- فقط RETURN انتهای تابع را با این جایگزین کن

RETURN jsonb_build_object(
  'geo',
    jsonb_build_object(
      'type', 'Feature',
      'geometry', ST_AsGeoJSON(ST_Transform(p_geom, 4326))::jsonb,
      'properties', jsonb_build_object(
        'source', p_source,
        'floor', p_floor,
        'mode', p_mode,
        'distanceMeters', v_distance_m,
        'estimatedMinutes', v_estimated_min
      )
    ),

  -- برای سازگاری اگر جایی geometry مستقیم می‌خواند
  'geometry', ST_AsGeoJSON(ST_Transform(p_geom, 4326))::jsonb,

  'steps', v_steps,
  'sahns', v_sahns,
  'viaPoints', v_via_points,
  'estimatedMinutes', v_estimated_min,
  'distanceMeters', v_distance_m,
  'source', p_source
);
END;
$$;


--
-- Name: fn_build_routing_edges(smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_build_routing_edges(p_floor smallint) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_area record;
    v_area_count integer := 0;
    v_area_ok integer := 0;
    v_area_failed integer := 0;
    v_area_result jsonb;
    v_door_result jsonb;
    v_total_edges integer := 0;
    v_intra_edges integer := 0;
    v_door_edges integer := 0;
BEGIN
    --------------------------------------------------------------------
    -- این تابع دیگر منطق قدیمی ساخت edge مستقیم بین همه درب‌های area را
    -- اجرا نمی‌کند.
    --
    -- منطق جدید:
    -- 1) edgeهای داخل محدوده با fn_rebuild_graph_for_area
    -- 2) edgeهای عبور از درب با door_access_points
    --------------------------------------------------------------------

    FOR v_area IN
        SELECT
            a.id,
            a.floor,
            a.area_type,
            COALESCE(a.attrs, '{}'::jsonb) AS attrs
        FROM public.areas a
        WHERE a.floor = p_floor
          AND public.fn_area_routing_role(
                a.area_type,
                COALESCE(a.attrs, '{}'::jsonb)
              ) = 'routable'
          AND EXISTS (
              SELECT 1
              FROM public.routing_nodes rn
              WHERE rn.floor = a.floor
                AND rn.area_id = a.id
          )
        ORDER BY a.id
    LOOP
        v_area_count := v_area_count + 1;

        BEGIN
            ----------------------------------------------------------------
            -- امضای تابع قبلی شما طبق خطاهای قبلی:
            -- fn_rebuild_graph_for_area(bigint, smallint, boolean, double precision)
            ----------------------------------------------------------------
            SELECT public.fn_rebuild_graph_for_area(
                v_area.id::bigint,
                p_floor::smallint,
                TRUE,
								2.7,
                0.2::double precision
            )
            INTO v_area_result;

            v_area_ok := v_area_ok + 1;

        EXCEPTION WHEN OTHERS THEN
            v_area_failed := v_area_failed + 1;

            RAISE NOTICE 'fn_rebuild_graph_for_area failed. area_id=%, error=%',
                v_area.id,
                SQLERRM;
        END;
    END LOOP;

    --------------------------------------------------------------------
    -- بعد از ساخت intra-area edgeها، edgeهای عبور از درب ساخته می‌شوند.
    --------------------------------------------------------------------
    SELECT public.fn_build_door_transition_edges_from_dap(p_floor)
    INTO v_door_result;

    SELECT count(*)::integer
    INTO v_total_edges
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor;

    SELECT count(*)::integer
    INTO v_door_edges
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor
      AND e.door_id IS NOT NULL;

    SELECT count(*)::integer
    INTO v_intra_edges
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor
      AND e.door_id IS NULL;

    RETURN jsonb_build_object(
        'ok', true,
        'floor', p_floor,
        'areas_seen', v_area_count,
        'areas_rebuilt_ok', v_area_ok,
        'areas_failed', v_area_failed,
        'door_transition_result', v_door_result,
        'total_edges', v_total_edges,
        'intra_area_edges', v_intra_edges,
        'door_transition_edges', v_door_edges,
        'builder', 'fn_rebuild_graph_for_area_plus_dap_transition'
    );
END;
$$;


--
-- Name: fn_build_routing_nodes(smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_build_routing_nodes(p_floor smallint) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_deleted_edge_areas integer := 0;
    v_deleted_nodes integer := 0;
    v_deleted_edges integer := 0;
    v_inserted_from integer := 0;
    v_inserted_to integer := 0;
    v_orphan_nodes integer := 0;
BEGIN
    DELETE FROM public.routing_edge_areas rea
    USING public.routing_edges_static e
    WHERE rea.edge_id = e.id
      AND e.floor = p_floor;

    GET DIAGNOSTICS v_deleted_edge_areas = ROW_COUNT;

    DELETE FROM public.routing_edges_static e
    WHERE e.floor = p_floor;

    GET DIAGNOSTICS v_deleted_edges = ROW_COUNT;

    DELETE FROM public.routing_nodes rn
    WHERE rn.floor = p_floor
      AND rn.ref_table = 'door_access_points';

    GET DIAGNOSTICS v_deleted_nodes = ROW_COUNT;

    INSERT INTO public.routing_nodes (
        floor,
        geom,
        kind,
        ref_table,
        ref_id,
        area_id
    )
    SELECT
        dap.floor,
        dap.geom::geometry(Point, 32640),
        'door_access'::text,
        'door_access_points'::text,
        dap.id,
        dap.from_area
    FROM public.door_access_points dap
    WHERE dap.floor = p_floor
      AND dap.geom IS NOT NULL
      AND dap.from_area IS NOT NULL
      AND COALESCE(dap.needs_review, false) = false;

    GET DIAGNOSTICS v_inserted_from = ROW_COUNT;

    INSERT INTO public.routing_nodes (
        floor,
        geom,
        kind,
        ref_table,
        ref_id,
        area_id
    )
    SELECT
        dap.floor,
        dap.geom::geometry(Point, 32640),
        'door_access'::text,
        'door_access_points'::text,
        dap.id,
        dap.to_area
    FROM public.door_access_points dap
    WHERE dap.floor = p_floor
      AND dap.geom IS NOT NULL
      AND dap.to_area IS NOT NULL
      AND COALESCE(dap.needs_review, false) = false;

    GET DIAGNOSTICS v_inserted_to = ROW_COUNT;

    SELECT count(*)::integer
    INTO v_orphan_nodes
    FROM public.routing_nodes rn
    LEFT JOIN public.door_access_points dap
      ON dap.id = rn.ref_id
     AND dap.floor = rn.floor
    WHERE rn.floor = p_floor
      AND rn.ref_table = 'door_access_points'
      AND dap.id IS NULL;

    RETURN jsonb_build_object(
        'ok', true,
        'floor', p_floor,
        'deleted_edge_areas', v_deleted_edge_areas,
        'deleted_edges', v_deleted_edges,
        'deleted_nodes', v_deleted_nodes,
        'inserted_from_nodes', v_inserted_from,
        'inserted_to_nodes', v_inserted_to,
        'inserted_total_nodes', v_inserted_from + v_inserted_to,
        'orphan_nodes_after', v_orphan_nodes,
        'source', 'door_access_points'
    );
END;
$$;


--
-- Name: fn_build_routing_nodes_old(smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_build_routing_nodes_old(p_floor smallint) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    --------------------------------------------------------------------
    -- مهم:
    -- این تابع rebuild واقعی nodeهاست.
    -- پس قبل از حذف nodeها، edgeهای همان طبقه حذف می‌شوند
    -- چون routing_edges_static روی routing_nodes FK دارد.
    --------------------------------------------------------------------
    DELETE FROM public.routing_edges_static
    WHERE floor = p_floor;

    DELETE FROM public.routing_nodes
    WHERE floor = p_floor;

    --------------------------------------------------------------------
    -- از این به بعد نودها فقط از door_access_points ساخته می‌شوند.
    --
    -- doors.geom فقط خط خام نمایشی درب است.
    -- door_access_points.geom نقطه مجاز اتصال است.
    -- routing_nodes.geom فقط از door_access_points.geom می‌آید.
    --------------------------------------------------------------------

    --------------------------------------------------------------------
    -- نود سمت from_area
    --------------------------------------------------------------------
    INSERT INTO public.routing_nodes (
        floor,
        geom,
        kind,
        ref_table,
        ref_id,
        area_id
    )
    SELECT
        dap.floor,
        dap.geom,
        'door_access'::text AS kind,
        'door_access_points'::text AS ref_table,
        dap.id AS ref_id,
        dap.from_area AS area_id
    FROM public.door_access_points dap
    WHERE dap.floor = p_floor
      AND dap.from_area IS NOT NULL
      AND COALESCE(dap.needs_review, false) = false;

    --------------------------------------------------------------------
    -- نود سمت to_area
    --------------------------------------------------------------------
    INSERT INTO public.routing_nodes (
        floor,
        geom,
        kind,
        ref_table,
        ref_id,
        area_id
    )
    SELECT
        dap.floor,
        dap.geom,
        'door_access'::text AS kind,
        'door_access_points'::text AS ref_table,
        dap.id AS ref_id,
        dap.to_area AS area_id
    FROM public.door_access_points dap
    WHERE dap.floor = p_floor
      AND dap.to_area IS NOT NULL
      AND COALESCE(dap.needs_review, false) = false;

    RAISE NOTICE 'routing_nodes rebuilt from door_access_points for floor %, nodes: %',
        p_floor,
        (
            SELECT count(*)
            FROM public.routing_nodes rn
            WHERE rn.floor = p_floor
        );
END;
$$;


--
-- Name: fn_category_path(bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_category_path(p_leaf_id bigint) RETURNS TABLE(level1_id bigint, level1_code text, level2_id bigint, level2_code text, level3_id bigint, level3_code text, level4_id bigint, level4_code text, level5_id bigint, level5_code text, leaf_id bigint, leaf_code text)
    LANGUAGE sql STABLE
    AS $$
WITH RECURSIVE cte AS (
  SELECT c.id, c.code, c.parent_id, c.level
  FROM   categories c
  WHERE  c.id = p_leaf_id

  UNION ALL

  SELECT p.id, p.code, p.parent_id, p.level
  FROM   categories p
  JOIN   cte ON p.parent_id = cte.id
)
SELECT
  MAX(CASE WHEN level = 1 THEN id   END) AS level1_id,
  MAX(CASE WHEN level = 1 THEN code END) AS level1_code,
  MAX(CASE WHEN level = 2 THEN id   END) AS level2_id,
  MAX(CASE WHEN level = 2 THEN code END) AS level2_code,
  MAX(CASE WHEN level = 3 THEN id   END) AS level3_id,
  MAX(CASE WHEN level = 3 THEN code END) AS level3_code,
  MAX(CASE WHEN level = 4 THEN id   END) AS level4_id,
  MAX(CASE WHEN level = 4 THEN code END) AS level4_code,
  MAX(CASE WHEN level = 5 THEN id   END) AS level5_id,
  MAX(CASE WHEN level = 5 THEN code END) AS level5_code,
  MAX(id)   AS leaf_id,
  MAX(code) AS leaf_code
FROM cte;
$$;


--
-- Name: fn_clear_area_mesh(bigint, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_clear_area_mesh(p_area_id bigint, p_floor smallint) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
  DELETE FROM public.mesh_adjacency ma
  USING public.mesh_triangles ta
  WHERE ta.id = ma.tri_a
    AND ta.area_id = p_area_id
    AND ta.floor = p_floor;

  DELETE FROM public.mesh_adjacency ma
  USING public.mesh_triangles tb
  WHERE tb.id = ma.tri_b
    AND tb.area_id = p_area_id
    AND tb.floor = p_floor;

  DELETE FROM public.mesh_triangles mt
  WHERE mt.area_id = p_area_id
    AND mt.floor = p_floor;
END;
$$;


--
-- Name: fn_create_door_from_click(double precision, double precision, smallint, public.gender_enum, boolean, text[], boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_create_door_from_click(p_x double precision, p_y double precision, p_floor smallint, p_allowed_gender public.gender_enum DEFAULT 'both'::public.gender_enum, p_is_open boolean DEFAULT true, p_modes text[] DEFAULT ARRAY['walk'::text, 'wheelchair'::text], p_bidirectional boolean DEFAULT true) RETURNS TABLE(door_id bigint, door_geom public.geometry, door_from_area bigint, door_to_area bigint, dap_id bigint, dap_geom public.geometry)
    LANGUAGE plpgsql
    AS $$
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
    ORDER BY a.geom <-> v_click_pt
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
    v_dap_geom := ST_LineInterpolatePoint(v_door_geom, 0.5);

    -- 6) محدوده مقابل (اگر باشد)
    SELECT a2.id
    INTO v_neighbor_area
    FROM areas a2
    WHERE a2.floor = p_floor
      AND a2.id <> v_area_id
      AND ST_DWithin(a2.geom, v_dap_geom, 1.0)
    ORDER BY ST_Distance(a2.geom, v_dap_geom)
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
        to_area
    )
    VALUES (
        door_id,
        v_dap_geom,
        p_floor,
        v_area_id,
        v_neighbor_area
    )
    RETURNING id, geom
    INTO dap_id, dap_geom;

    -- 9) خروجی تابع (یک ردیف)
    RETURN QUERY
        SELECT door_id, door_geom, door_from_area, door_to_area, dap_id, dap_geom;

    RETURN;
END;
$$;


--
-- Name: fn_dap_node_geom_inside_area(public.geometry, bigint, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_dap_node_geom_inside_area(p_dap_geom public.geometry, p_area_id bigint, p_clearance_m double precision DEFAULT 0.20) RETURNS public.geometry
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    v_area geometry;
    v_inner geometry;
    v_pt geometry(Point, 32640);
BEGIN
    IF p_dap_geom IS NULL OR ST_IsEmpty(p_dap_geom) THEN
        RETURN NULL;
    END IF;

    SELECT ST_MakeValid(a.geom)::geometry
    INTO v_area
    FROM public.areas a
    WHERE a.id = p_area_id;

    IF v_area IS NULL OR ST_IsEmpty(v_area) THEN
        RETURN p_dap_geom::geometry(Point, 32640);
    END IF;

    --------------------------------------------------------------------
    -- نقطه DAP روی مرز است. برای اینکه در intra-area graph شرکت کند،
    -- node مربوط به این area را کمی داخل area می‌بریم.
    --------------------------------------------------------------------
    v_inner := ST_Buffer(v_area, -GREATEST(COALESCE(p_clearance_m, 0.20), 0.05));

    IF v_inner IS NULL OR ST_IsEmpty(v_inner) THEN
        v_inner := v_area;
    END IF;

    v_pt := ST_ClosestPoint(v_inner, p_dap_geom)::geometry(Point, 32640);

    IF v_pt IS NULL OR ST_IsEmpty(v_pt) THEN
        RETURN p_dap_geom::geometry(Point, 32640);
    END IF;

    RETURN v_pt;
END;
$$;


--
-- Name: fn_date_in_scope(date, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_date_in_scope(p_date date, p_scope jsonb) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_list text[];
BEGIN
    IF p_scope IS NULL OR jsonb_typeof(p_scope) <> 'array' THEN
        RETURN false;
    END IF;

    -- همه روزها
    IF EXISTS (SELECT 1
               FROM jsonb_array_elements_text(p_scope) as t(val)
               WHERE upper(trim(val)) = 'ALL_DAYS') THEN
        RETURN true;
    END IF;

    -- لیست رشته‌ها
    SELECT array_agg(trim(t.val))
    INTO   v_list
    FROM   jsonb_array_elements_text(p_scope) as t(val);

    IF v_list IS NULL OR array_length(v_list, 1) = 0 THEN
        RETURN false;
    END IF;

    -- اگر یک تاریخ تنها بود
    IF array_length(v_list, 1) = 1 THEN
        RETURN (p_date = v_list[1]::date);
    END IF;

    -- اگر دو تاریخ بود و بازه باشد [start,end]
    IF array_length(v_list, 1) >= 2 THEN
        RETURN (p_date BETWEEN v_list[1]::date AND v_list[2]::date);
    END IF;

    RETURN false;
END;
$$;


--
-- Name: fn_date_in_scope_json(date, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_date_in_scope_json(p_date date, p_scope jsonb) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_arr text[];
BEGIN
    IF p_scope IS NULL OR jsonb_typeof(p_scope) <> 'array' THEN
        RETURN FALSE;
    END IF;

    SELECT array_agg(e)
    INTO   v_arr
    FROM   jsonb_array_elements_text(p_scope) AS e;

    IF v_arr IS NULL OR array_length(v_arr,1) = 0 THEN
        RETURN FALSE;
    END IF;

    -- ALL_DAYS
    IF EXISTS (SELECT 1 FROM unnest(v_arr) AS x WHERE upper(x) = 'ALL_DAYS') THEN
        RETURN TRUE;
    END IF;

    -- یک تاریخ
    IF array_length(v_arr,1) = 1 THEN
        RETURN p_date = v_arr[1]::date;
    END IF;

    -- بازه start / end
    IF array_length(v_arr,1) >= 2 THEN
        RETURN p_date BETWEEN v_arr[1]::date AND v_arr[2]::date;
    END IF;

    RETURN FALSE;
END;
$$;


--
-- Name: fn_delete_door_cascade(bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_delete_door_cascade(p_door_id bigint) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- اول نقاط دسترسی
    DELETE FROM door_access_points
    WHERE door_id = p_door_id;

    -- سپس خود درب
    DELETE FROM doors
    WHERE id = p_door_id;
END;
$$;


--
-- Name: fn_door_access_points_mvt(integer, integer, integer, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_door_access_points_mvt(z integer, x integer, y integer, p_floor smallint DEFAULT NULL::smallint) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857   geometry;  -- bbox تایل در 3857 (WebMercator)
  tile_bbox_32640  geometry;  -- همان bbox در SRID داده‌ها (32640)
BEGIN
  -- bbox تایل در WebMercator
  tile_bbox_3857 := ST_TileEnvelope(z, x, y);

  -- تبدیل bbox به سیستم مختصات داده‌ها (32640)
  tile_bbox_32640 := ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT ST_AsMVT(t, 'door_access_points', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(dap.geom, 3857), -- تبدیل نقطه به 3857
          tile_bbox_3857,               -- bbox در 3857
          4096,
          64,
          TRUE
        ) AS geom,

        -- فیلدهای کلیدی خود نقطه
        dap.id,
        dap.door_id,
        dap.floor,
        dap.from_area,
        dap.to_area,

        -- اطلاعات توصیفی درب اصلی از جدول doors
        d.allowed_gender,
        d.is_open,
        d.modes,
        d.bidirectional,
        d.attrs

      FROM door_access_points AS dap
      JOIN doors AS d
        ON d.id = dap.door_id

      WHERE
        (p_floor IS NULL OR dap.floor = p_floor)
        AND dap.geom && tile_bbox_32640
        AND ST_Intersects(dap.geom, tile_bbox_32640)
    ) AS t
    WHERE geom IS NOT NULL
  );
END;
$$;


--
-- Name: fn_door_active_restriction_title(jsonb, timestamp with time zone, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_door_active_restriction_title(p_attrs jsonb, p_at timestamp with time zone, p_gender text DEFAULT NULL::text) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_today date := p_at::date;
    v_now   time := p_at::time;
    v_tr    jsonb;
    v_pr    jsonb;
    v_ranges jsonb;
    v_range jsonb;
    v_events jsonb;
    v_event text;
    v_prayer_time time;
    v_before int;
    v_after  int;
    v_start_ts timestamptz;
    v_end_ts   timestamptz;
BEGIN
    IF p_attrs IS NULL THEN
        RETURN NULL;
    END IF;

    ------------------------------------------------------------------
    -- ۱) بررسی محدودیت‌های زمانی (time_restrictions)
    ------------------------------------------------------------------
    FOR v_tr IN SELECT * FROM jsonb_array_elements(p_attrs->'time_restrictions')
    LOOP
        IF NOT fn_date_in_scope(v_today, v_tr->'date_scope') THEN
            CONTINUE;
        END IF;

        -- جنسیت اگر لازم بود
        IF p_gender IS NOT NULL AND v_tr->'gender' IS NOT NULL THEN
            IF NOT EXISTS (
                SELECT 1 FROM jsonb_array_elements_text(v_tr->'gender') g WHERE g = p_gender
            ) THEN
                CONTINUE;
            END IF;
        END IF;

        -- all_hours
        IF (v_tr->>'all_hours')::boolean = true THEN
            RETURN v_tr->>'title';
        END IF;

        -- time_ranges
        FOR v_range IN SELECT * FROM jsonb_array_elements(v_tr->'time_ranges')
        LOOP
            IF v_now >= (v_range->>'start')::time
               AND v_now <= (v_range->>'end')::time THEN
                RETURN v_tr->>'title';
            END IF;
        END LOOP;
    END LOOP;


    ------------------------------------------------------------------
    -- ۲) بررسی محدودیت‌های نماز (prayer_restrictions)
    ------------------------------------------------------------------
    FOR v_pr IN SELECT * FROM jsonb_array_elements(p_attrs->'prayer_restrictions')
    LOOP
        IF NOT fn_date_in_scope(v_today, v_pr->'date_scope') THEN
            CONTINUE;
        END IF;

        v_events := v_pr->'events';
        v_before := COALESCE((v_pr->>'before_minutes')::int, 0);
        v_after  := COALESCE((v_pr->>'after_minutes')::int, 0);

        FOR v_event IN SELECT jsonb_array_elements_text(v_events)
        LOOP
            SELECT pt.time
            INTO v_prayer_time
            FROM prayer_times pt
            WHERE pt.day_date = v_today
              AND pt.event = v_event
            LIMIT 1;

            IF NOT FOUND THEN
                CONTINUE;
            END IF;

            v_start_ts := (v_today::timestamp + v_prayer_time) - (v_before * interval '1 minute');
            v_end_ts   := (v_today::timestamp + v_prayer_time) + (v_after  * interval '1 minute');

            IF p_at BETWEEN v_start_ts AND v_end_ts THEN
                RETURN v_pr->>'title';
            END IF;
        END LOOP;
    END LOOP;

    RETURN NULL;
END;
$$;


--
-- Name: fn_door_blocked_by_prayer_restrictions(jsonb, timestamp with time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_door_blocked_by_prayer_restrictions(p_attrs jsonb, p_at timestamp with time zone) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_today      date := p_at::date;
    v_pr         jsonb;
    v_events     jsonb;
    v_event_name text;
    v_before     int;
    v_after      int;
    v_prayer_time time;
    v_start_ts   timestamptz;
    v_end_ts     timestamptz;
BEGIN
    IF p_attrs IS NULL THEN
        RETURN false;
    END IF;

    IF p_attrs->'prayer_restrictions' IS NULL THEN
        RETURN false;
    END IF;

    FOR v_pr IN SELECT * FROM jsonb_array_elements(p_attrs->'prayer_restrictions')
    LOOP
        -- تاریخ
        IF NOT fn_date_in_scope(v_today, v_pr->'date_scope') THEN
            CONTINUE;
        END IF;

        v_before := COALESCE((v_pr->>'before_minutes')::int, 0);
        v_after  := COALESCE((v_pr->>'after_minutes')::int, 0);
        v_events := v_pr->'events';

        IF v_events IS NULL OR jsonb_typeof(v_events) <> 'array' THEN
            CONTINUE;
        END IF;

        FOR v_event_name IN SELECT jsonb_array_elements_text(v_events)
        LOOP
            -- گرفتن ساعت نماز از prayer_times
            SELECT pt."time"
            INTO   v_prayer_time
            FROM   prayer_times pt
            WHERE  pt.day_date = v_today
                   AND pt.event = v_event_name
            LIMIT 1;

            IF NOT FOUND THEN
                CONTINUE;
            END IF;

            v_start_ts := make_timestamp(
                              EXTRACT(YEAR  FROM v_today)::int,
                              EXTRACT(MONTH FROM v_today)::int,
                              EXTRACT(DAY   FROM v_today)::int,
                              EXTRACT(HOUR  FROM v_prayer_time)::int,
                              EXTRACT(MINUTE FROM v_prayer_time)::int,
                              EXTRACT(SECOND FROM v_prayer_time)
                          ) - (v_before * interval '1 minute');

            v_end_ts   := make_timestamp(
                              EXTRACT(YEAR  FROM v_today)::int,
                              EXTRACT(MONTH FROM v_today)::int,
                              EXTRACT(DAY   FROM v_today)::int,
                              EXTRACT(HOUR  FROM v_prayer_time)::int,
                              EXTRACT(MINUTE FROM v_prayer_time)::int,
                              EXTRACT(SECOND FROM v_prayer_time)
                          ) + (v_after * interval '1 minute');

            IF p_at BETWEEN v_start_ts AND v_end_ts THEN
                RETURN true;
            END IF;
        END LOOP;
    END LOOP;

    RETURN false;
END;
$$;


--
-- Name: fn_door_blocked_by_time_restrictions(jsonb, timestamp with time zone, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_door_blocked_by_time_restrictions(p_attrs jsonb, p_at timestamp with time zone, p_gender text DEFAULT NULL::text) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_today date := p_at::date;
    v_now   time := p_at::time;
    v_tr    jsonb;
    v_ranges jsonb;
    v_range  jsonb;
    v_gender jsonb;
    v_all_hours boolean;
BEGIN
    IF p_attrs IS NULL THEN
        RETURN false;
    END IF;

    IF p_attrs->'time_restrictions' IS NULL THEN
        RETURN false;
    END IF;

    FOR v_tr IN SELECT * FROM jsonb_array_elements(p_attrs->'time_restrictions')
    LOOP
        -- تاریخ
        IF NOT fn_date_in_scope(v_today, v_tr->'date_scope') THEN
            CONTINUE;
        END IF;

        -- جنسیت (اگر تعریف شده بود)
        v_gender := v_tr->'gender';
        IF v_gender IS NOT NULL
           AND jsonb_typeof(v_gender) = 'array'
           AND p_gender IS NOT NULL THEN
            IF NOT EXISTS (
                SELECT 1
                FROM jsonb_array_elements_text(v_gender) as g(val)
                WHERE g.val = p_gender
            ) THEN
                CONTINUE;
            END IF;
        END IF;

        v_all_hours := COALESCE( (v_tr->>'all_hours')::boolean, false );

        IF v_all_hours THEN
            RETURN true;  -- این محدودیت کل روز را می‌بندد
        END IF;

        -- بررسی بازه‌های زمانی
        v_ranges := v_tr->'time_ranges';
        IF v_ranges IS NULL OR jsonb_typeof(v_ranges) <> 'array' THEN
            CONTINUE;
        END IF;

        FOR v_range IN SELECT * FROM jsonb_array_elements(v_ranges)
        LOOP
            IF v_range->>'start' IS NULL OR v_range->>'end' IS NULL THEN
                CONTINUE;
            END IF;

            IF v_now >= (v_range->>'start')::time
               AND v_now <= (v_range->>'end')::time THEN
                RETURN true;
            END IF;
        END LOOP;
    END LOOP;

    RETURN false;
END;
$$;


--
-- Name: fn_door_live_status(bigint, timestamp with time zone, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_door_live_status(p_door_id bigint, p_at timestamp with time zone, p_gender text DEFAULT NULL::text) RETURNS public.door_live_status_enum
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_door   doors%ROWTYPE;
    v_attrs  jsonb;
BEGIN
    SELECT * INTO v_door
    FROM doors
    WHERE id = p_door_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Door % not found', p_door_id;
    END IF;

    -- 1) اگر خود لایه بسته باشد
    IF v_door.is_open = false THEN
        RETURN 'closed_layer';
    END IF;

    v_attrs := v_door.attrs::jsonb;

    -- 2) محدودیت زمانی
    IF fn_door_blocked_by_time_restrictions(v_attrs, p_at, p_gender) THEN
        RETURN 'closed_time_restriction';
    END IF;

    -- 3) محدودیت اوقات شرعی
    IF fn_door_blocked_by_prayer_restrictions(v_attrs, p_at) THEN
        RETURN 'closed_prayer_restriction';
    END IF;

    -- در غیر این صورت باز است
    RETURN 'open';
END;
$$;


--
-- Name: fn_door_live_status_ext(timestamp with time zone, public.gender_enum); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_door_live_status_ext(p_at timestamp with time zone DEFAULT now(), p_gender public.gender_enum DEFAULT NULL::public.gender_enum) RETURNS TABLE(door_id bigint, floor smallint, base_is_open boolean, base_status text, live_status text, limited_by_time boolean, limited_by_prayer boolean, active_time_title text, active_prayer_title text, active_rule_type text, active_rule_id bigint)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_d          doors%ROWTYPE;
    v_today      date := p_at::date;
    v_now        time := p_at::time;
    v_oper       jsonb;
    v_row        door_schedules%ROWTYPE;
    v_note       jsonb;
    v_prayer_time time;
    v_before     int;
    v_after      int;
    v_start_ts   timestamptz;
    v_end_ts     timestamptz;
BEGIN
    FOR v_d IN SELECT * FROM doors LOOP
        door_id := v_d.id;
        floor   := v_d.floor;

        v_oper := COALESCE(v_d.attrs->'operational', '{}'::jsonb);

        base_is_open := COALESCE(v_d.is_open, TRUE);
        base_status  := COALESCE(
                            v_oper->>'status',
                            CASE WHEN base_is_open THEN 'open' ELSE 'closed' END
                        );

        limited_by_time     := FALSE;
        limited_by_prayer   := FALSE;
        active_time_title   := NULL;
        active_prayer_title := NULL;
        active_rule_type    := NULL;
        active_rule_id      := NULL;

        ----------------------------------------------------------------
        -- اگر خود لایه بسته است، نیازی به چک محدودیت‌ها نیست
        ----------------------------------------------------------------
        IF base_is_open = FALSE OR base_status = 'closed' THEN
            live_status := 'closed';
            RETURN NEXT;
            CONTINUE;
        END IF;

        ----------------------------------------------------------------
        -- 1) محدودیت‌های زمانی از door_schedules (rule_type = time_restriction)
        ----------------------------------------------------------------
        FOR v_row IN
            SELECT *
            FROM door_schedules ds
            WHERE ds.door_id  = v_d.id
              AND ds.rule_type = 'time_restriction'
        LOOP
            v_note := v_row.note::jsonb;

            -- date_scope
            IF v_note ? 'date_scope' THEN
                IF NOT fn_date_in_scope_json(v_today, v_note->'date_scope') THEN
                    CONTINUE;
                END IF;
            END IF;

            -- gender (اگر p_gender ارسال شده)
            IF p_gender IS NOT NULL AND v_note ? 'gender' THEN
                IF NOT EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements_text(v_note->'gender') AS g(val)
                    WHERE g.val = p_gender::text
                ) THEN
                    CONTINUE;
                END IF;
            END IF;

            -- all_hours=false → فقط بازهٔ زمانی schedule
            IF COALESCE((v_note->>'all_hours')::boolean, FALSE) = FALSE THEN
                IF NOT (v_now BETWEEN v_row.time_from AND v_row.time_to) THEN
                    CONTINUE;
                END IF;
            END IF;

            -- این محدودیت الان فعّال است
            limited_by_time   := TRUE;
            active_time_title := v_note->>'title';
            active_rule_type  := 'time_restriction';
            active_rule_id    := v_row.id;
            EXIT; -- اولین محدودیت کافی است
        END LOOP;

        ----------------------------------------------------------------
        -- 2) محدودیت‌های نماز از door_schedules (rule_type = prayer)
        ----------------------------------------------------------------
        FOR v_row IN
            SELECT *
            FROM door_schedules ds
            WHERE ds.door_id  = v_d.id
              AND ds.rule_type = 'prayer'
        LOOP
            v_note := v_row.note::jsonb;

            -- date_scope
            IF v_note ? 'date_scope' THEN
                IF NOT fn_date_in_scope_json(v_today, v_note->'date_scope') THEN
                    CONTINUE;
                END IF;
            END IF;

            v_before := COALESCE((v_note->>'before_minutes')::int, 0);
            v_after  := COALESCE((v_note->>'after_minutes')::int, 0);

            -- فرض: در هر ردیف فقط یک event داریم (field: event)
            SELECT pt.event_time   -- 👈 اینجا نام ستون واقعی زمان نماز را بگذارید
INTO   v_prayer_time
FROM   prayer_times pt
WHERE  pt.day_date = v_today
  AND  pt.event    = v_event_name   -- یا v_note->>'event' در نسخه تک‌رویدادی
LIMIT 1;


            IF NOT FOUND THEN
                CONTINUE;
            END IF;

            v_start_ts := (v_today::timestamp + v_prayer_time) - (v_before * interval '1 minute');
            v_end_ts   := (v_today::timestamp + v_prayer_time) + (v_after  * interval '1 minute');

            IF p_at BETWEEN v_start_ts AND v_end_ts THEN
                limited_by_prayer   := TRUE;
                active_prayer_title := v_note->>'title';

                -- اگر از قبل محدودیت زمانی ثبت نشده بود، این را به عنوان active_rule در نظر بگیر
                IF active_rule_type IS NULL THEN
                    active_rule_type := 'prayer';
                    active_rule_id   := v_row.id;
                END IF;

                EXIT;
            END IF;
        END LOOP;

        ----------------------------------------------------------------
        -- 3) live_status نهایی
        ----------------------------------------------------------------
        IF limited_by_time OR limited_by_prayer THEN
            live_status := 'limited';
        ELSE
            live_status := 'open';
        END IF;

        RETURN NEXT;
    END LOOP;

    RETURN;
END;
$$;


--
-- Name: fn_door_side_sample_points(public.geometry, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_door_side_sample_points(p_line public.geometry, p_offset_m double precision DEFAULT 0.40) RETURNS TABLE(side text, geom public.geometry)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_line geometry(LineString, 32640);
    v_p1 geometry(Point, 32640);
    v_p2 geometry(Point, 32640);
    v_mid geometry(Point, 32640);
    v_az double precision;
    v_left_az double precision;
    v_right_az double precision;
BEGIN
    IF p_line IS NULL OR ST_IsEmpty(p_line) THEN
        RETURN;
    END IF;

    SELECT (dmp).geom::geometry(LineString, 32640)
    INTO v_line
    FROM ST_Dump(
      ST_CollectionExtract(
        ST_MakeValid(p_line),
        2
      )
    ) AS dmp
    WHERE NOT ST_IsEmpty((dmp).geom)
    ORDER BY ST_Length((dmp).geom) DESC
    LIMIT 1;

    IF v_line IS NULL OR ST_Length(v_line) <= 0.05 THEN
        RETURN;
    END IF;

    v_mid := ST_LineInterpolatePoint(v_line, 0.5)::geometry(Point, 32640);
    v_p1  := ST_LineInterpolatePoint(v_line, 0.45)::geometry(Point, 32640);
    v_p2  := ST_LineInterpolatePoint(v_line, 0.55)::geometry(Point, 32640);

    v_az := ST_Azimuth(v_p1, v_p2);

    IF v_az IS NULL THEN
        RETURN;
    END IF;

    v_left_az  := v_az - pi() / 2.0;
    v_right_az := v_az + pi() / 2.0;

    RETURN QUERY
    SELECT
      'left'::text,
      ST_SetSRID(
        ST_MakePoint(
          ST_X(v_mid) + p_offset_m * sin(v_left_az),
          ST_Y(v_mid) + p_offset_m * cos(v_left_az)
        ),
        32640
      )::geometry(Point, 32640)

    UNION ALL

    SELECT
      'right'::text,
      ST_SetSRID(
        ST_MakePoint(
          ST_X(v_mid) + p_offset_m * sin(v_right_az),
          ST_Y(v_mid) + p_offset_m * cos(v_right_az)
        ),
        32640
      )::geometry(Point, 32640);
END;
$$;


--
-- Name: fn_doors_mvt(integer, integer, integer, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_doors_mvt(z integer, x integer, y integer, p_floor smallint DEFAULT NULL::smallint) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857   geometry;  -- bbox تایل در 3857
  tile_bbox_32640  geometry;  -- همان bbox در SRID داده‌ها
BEGIN
  -- bbox تایل در WebMercator
  tile_bbox_3857 := ST_TileEnvelope(z, x, y);

  -- تبدیل bbox به سیستم مختصات داده‌ها (32640)
  tile_bbox_32640 := ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT ST_AsMVT(t, 'doors', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(a.geom, 3857),  -- تبدیل داده‌ها به 3857 برای MVT
          tile_bbox_3857,              -- bbox در همان 3857
          4096,
          64,
          true
        ) AS geom,
        a.id,
        a.from_area,
        a.to_area,
        a.floor,
        a.allowed_gender,
        a.is_open,
        a.modes,
        a.bidirectional,
        a.attrs
      FROM doors a
      WHERE
        (p_floor IS NULL OR a.floor = p_floor)
        AND a.geom && tile_bbox_32640
        AND ST_Intersects(a.geom, tile_bbox_32640)
    ) AS t
    WHERE geom IS NOT NULL
  );
END;
$$;


--
-- Name: fn_entity_access(text, bigint, timestamp with time zone, public.gender_enum, text, smallint, public.geometry); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_entity_access(p_entity_table text, p_entity_id bigint, p_ts timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint DEFAULT NULL::smallint, p_geom public.geometry DEFAULT NULL::public.geometry) RETURNS TABLE(allowed boolean, penalty_w numeric, restriction_type text)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_now timestamptz := p_ts;

    -- تمام Ruleهای زمانی بر مبنای ساعت محلی مشهد/تهران
    v_now_local timestamp;
    v_date date;
    v_time time;

    v_allowed boolean := TRUE;
    v_penalty numeric := 1.0;
    v_type text := 'none';

    v_admin_close_penalty numeric;
    v_admin_penalty numeric;

    v_time_block_exists boolean := FALSE;
    v_prayer_block_exists boolean := FALSE;

    c_time_penalty constant numeric := 10.0;
    c_prayer_penalty constant numeric := 8.0;

BEGIN

    v_now_local := p_ts AT TIME ZONE 'Asia/Tehran';
    v_date := v_now_local::date;
    v_time := v_now_local::time;

    ------------------------------------------------------------------
    -- 1. Admin Close
    ------------------------------------------------------------------

    SELECT MAX(r.penalty_w)
    INTO v_admin_close_penalty
    FROM public.admin_restrictions r
    WHERE r.is_active = TRUE

      AND (
          r.starts_at IS NULL
          OR r.starts_at <= v_now
      )

      AND (
          r.ends_at IS NULL
          OR v_now <= r.ends_at
      )

      AND (
          r.gender IS NULL

          OR r.gender = 'both'::gender_enum

          OR r.gender = p_gender

          -- both = family
          -- پس محدودیت male یا female نیز روی خانواده اثر دارد
          OR p_gender = 'both'::gender_enum
      )

      AND (
          r.modes IS NULL
          OR p_mode = ANY(r.modes)
      )

      AND r.restrict_type = 'close'

      AND (
          (
              r.target_table = p_entity_table
              AND r.target_id = p_entity_id
          )

          OR

          (
              r.target_table IS NULL
              AND r.target_id IS NULL

              AND (
                  r.floor IS NULL
                  OR r.floor = p_floor
              )

              AND (
                  r.geom IS NULL

                  OR (
                      p_geom IS NOT NULL
                      AND ST_Covers(
                          r.geom,
                          p_geom
                      )
                  )
              )
          )
      );

    IF v_admin_close_penalty IS NOT NULL THEN

        v_allowed := FALSE;

        v_penalty := GREATEST(
            COALESCE(v_penalty, 1.0),
            COALESCE(v_admin_close_penalty, 1.0)
        );

        v_type := 'admin_close';

    END IF;


    ------------------------------------------------------------------
    -- 2. Admin Penalty
    ------------------------------------------------------------------

    SELECT MAX(r.penalty_w)
    INTO v_admin_penalty
    FROM public.admin_restrictions r
    WHERE r.is_active = TRUE

      AND (
          r.starts_at IS NULL
          OR r.starts_at <= v_now
      )

      AND (
          r.ends_at IS NULL
          OR v_now <= r.ends_at
      )

      AND (
          r.gender IS NULL
          OR r.gender = 'both'::gender_enum
          OR r.gender = p_gender
          OR p_gender = 'both'::gender_enum
      )

      AND (
          r.modes IS NULL
          OR p_mode = ANY(r.modes)
      )

      AND r.restrict_type <> 'close'

      AND (
          (
              r.target_table = p_entity_table
              AND r.target_id = p_entity_id
          )

          OR

          (
              r.target_table IS NULL
              AND r.target_id IS NULL

              AND (
                  r.floor IS NULL
                  OR r.floor = p_floor
              )

              AND (
                  r.geom IS NULL

                  OR (
                      p_geom IS NOT NULL
                      AND ST_Covers(
                          r.geom,
                          p_geom
                      )
                  )
              )
          )
      );

    IF v_admin_penalty IS NOT NULL THEN

        v_penalty := GREATEST(
            COALESCE(v_penalty, 1.0),
            COALESCE(v_admin_penalty, 1.0)
        );

        IF v_type = 'none' THEN
            v_type := 'admin_penalty';

        ELSIF v_type <> 'admin_close' THEN
            v_type := 'mixed';

        END IF;

    END IF;


    ------------------------------------------------------------------
    -- 3. Time restrictions
    ------------------------------------------------------------------

    SELECT EXISTS (

        SELECT 1

        FROM public.access_time_restrictions r

        WHERE
            r.entity_table = p_entity_table

            AND r.entity_id = p_entity_id

            AND (
                r.specific_date IS NULL
                OR r.specific_date = v_date
            )

            AND (

                r.all_hours = TRUE

                OR

                (
                    r.start_time IS NOT NULL
                    AND r.end_time IS NOT NULL

                    AND (

                        -- بازه معمولی مانند 08:00 تا 12:00
                        (
                            r.start_time < r.end_time

                            AND v_time >= r.start_time

                            AND v_time < r.end_time
                        )

                        OR

                        -- بازه عبوری از نیمه‌شب
                        -- مثال 22:00 تا 02:00
                        (
                            r.start_time > r.end_time

                            AND (
                                v_time >= r.start_time
                                OR v_time < r.end_time
                            )
                        )

                        OR

                        -- start=end => تمام روز
                        (
                            r.start_time = r.end_time
                        )
                    )
                )
            )

            AND (

                -- NULL یا [] یعنی محدودیت برای همه
                r.gender IS NULL

                OR cardinality(r.gender) = 0

                -- both در Rule یعنی همه
                OR 'both'::gender_enum = ANY(r.gender)

                -- مرد/زن معمولی
                OR p_gender = ANY(r.gender)

                -- both در درخواست یعنی خانواده:
                -- اگر Rule مخصوص مرد یا زن باشد، خانواده هم نمی‌تواند عبور کند
                OR (
                    p_gender = 'both'::gender_enum

                    AND (
                        'male'::gender_enum = ANY(r.gender)
                        OR 'female'::gender_enum = ANY(r.gender)
                    )
                )
            )

    )
    INTO v_time_block_exists;


    IF v_time_block_exists THEN

        v_allowed := FALSE;

        v_penalty := GREATEST(
            COALESCE(v_penalty, 1.0),
            c_time_penalty
        );

        IF v_type = 'none' THEN
            v_type := 'time';

        ELSIF v_type <> 'time' THEN
            v_type := 'mixed';

        END IF;

    END IF;


    ------------------------------------------------------------------
    -- 4. Prayer restrictions
    ------------------------------------------------------------------

    SELECT EXISTS (

        SELECT 1

        FROM public.access_prayer_restrictions pr

        WHERE
            pr.entity_table = p_entity_table

            AND pr.entity_id = p_entity_id

            AND (
                pr.specific_date IS NULL
                OR pr.specific_date = v_date
            )

            AND (

                pr.gender IS NULL

                OR cardinality(pr.gender) = 0

                OR 'both'::gender_enum = ANY(pr.gender)

                OR p_gender = ANY(pr.gender)

                OR (
                    p_gender = 'both'::gender_enum

                    AND (
                        'male'::gender_enum = ANY(pr.gender)
                        OR 'female'::gender_enum = ANY(pr.gender)
                    )
                )
            )

            AND public.fn_prayer_event_time(
                    v_date,
                    pr.prayer_event
                ) IS NOT NULL

            AND v_now_local BETWEEN

                (
                    v_date::timestamp
                    + public.fn_prayer_event_time(
                        v_date,
                        pr.prayer_event
                      )
                    - make_interval(
                        mins => pr.before_minutes
                      )
                )

                AND

                (
                    v_date::timestamp
                    + public.fn_prayer_event_time(
                        v_date,
                        pr.prayer_event
                      )
                    + make_interval(
                        mins => pr.after_minutes
                      )
                )
    )
    INTO v_prayer_block_exists;


    IF v_prayer_block_exists THEN

        v_allowed := FALSE;

        v_penalty := GREATEST(
            COALESCE(v_penalty, 1.0),
            c_prayer_penalty
        );

        IF v_type = 'none' THEN
            v_type := 'prayer';

        ELSIF v_type <> 'prayer' THEN
            v_type := 'mixed';

        END IF;

    END IF;


    ------------------------------------------------------------------
    -- Result
    ------------------------------------------------------------------

    allowed :=
        COALESCE(v_allowed, TRUE);

    penalty_w :=
        COALESCE(v_penalty, 1.0);

    restriction_type :=
        COALESCE(v_type, 'none');

    RETURN NEXT;

    RETURN;

END;
$$;


--
-- Name: fn_fix_doors_from_to(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_fix_doors_from_to() RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
  RAISE NOTICE 'Recalculating doors.from_area / doors.to_area (ignoring stair/ramp/elevator areas)...';

  WITH params AS (
    SELECT
      0.15::double precision AS alpha,
      0.85::double precision AS beta,
      0.80::double precision AS rad_pt,
      1.00::double precision AS buf_eps
  ), calc AS (
    SELECT
      d.id,

      COALESCE(
        af.id,
        ab.area1_id
      ) AS new_from_area,

      COALESCE(
        CASE
          WHEN at.id IS NOT NULL
               AND (af.id IS NULL OR at.id <> af.id)
            THEN at.id
          WHEN ab.area2_id IS NOT NULL
            THEN ab.area2_id
          ELSE at.id
        END,
        af.id,
        ab.area2_id
      ) AS new_to_area

    FROM doors d
    CROSS JOIN params prm

    CROSS JOIN LATERAL (
      SELECT ST_LineInterpolatePoint(d.geom, prm.alpha) AS p_from_geom
    ) p_from

    CROSS JOIN LATERAL (
      SELECT ST_LineInterpolatePoint(d.geom, prm.beta) AS p_to_geom
    ) p_to

    -- نزدیک‌ترین area برای سمت from (به‌جز پله/رمپ/آسانسور)
    LEFT JOIN LATERAL (
      SELECT a.*
      FROM areas a
      WHERE a.floor = d.floor
        AND fn_area_routing_role(a.area_type, a.attrs) = 'routable'
        AND ST_DWithin(a.geom, p_from.p_from_geom, prm.rad_pt)
      ORDER BY
        CASE WHEN ST_Intersects(a.geom, p_from.p_from_geom) THEN 0 ELSE 1 END,
        ST_Distance(a.geom, p_from.p_from_geom)
      LIMIT 1
    ) af ON TRUE

    -- نزدیک‌ترین area برای سمت to (به‌جز پله/رمپ/آسانسور)
    LEFT JOIN LATERAL (
      SELECT a.*
      FROM areas a
      WHERE a.floor = d.floor
        AND fn_area_routing_role(a.area_type, a.attrs) = 'routable'
        AND ST_DWithin(a.geom, p_to.p_to_geom, prm.rad_pt)
      ORDER BY
        CASE WHEN ST_Intersects(a.geom, p_to.p_to_geom) THEN 0 ELSE 1 END,
        ST_Distance(a.geom, p_to.p_to_geom)
      LIMIT 1
    ) at ON TRUE

    -- دو area غالب بر اساس بیشترین intersection با بافر درب
    LEFT JOIN LATERAL (
      SELECT
        MAX(CASE WHEN rn = 1 THEN id END) AS area1_id,
        MAX(CASE WHEN rn = 2 THEN id END) AS area2_id
      FROM (
        SELECT
          a.id,
          ROW_NUMBER() OVER (
            ORDER BY
              ST_Area(
                ST_Intersection(
                  a.geom,
                  ST_Buffer(d.geom, prm.buf_eps)
                )
              ) DESC
          ) AS rn
        FROM areas a
        WHERE a.floor = d.floor
          AND fn_area_routing_role(a.area_type, a.attrs) = 'routable'
          AND ST_DWithin(a.geom, d.geom, prm.buf_eps * 2)
      ) s
    ) ab ON TRUE
  ),
  to_update AS (
    SELECT
      c.id,
      c.new_from_area,
      c.new_to_area
    FROM calc c
    WHERE
      (c.new_from_area IS NOT NULL AND c.new_from_area <> 0)
      OR
      (c.new_to_area IS NOT NULL AND c.new_to_area <> 0)
  )
  UPDATE doors d
  SET
    from_area = COALESCE(u.new_from_area, d.from_area),
    to_area   = COALESCE(u.new_to_area,   d.to_area)
  FROM to_update u
  WHERE d.id = u.id;

  RAISE NOTICE 'Doors from_area/to_area updated.';
END;
$$;


--
-- Name: fn_heading_penalty(public.geometry, public.geometry, public.geometry, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_heading_penalty(p_origin public.geometry, p_candidate public.geometry, p_destination public.geometry, p_penalty_m double precision DEFAULT 15.0) RETURNS double precision
    LANGUAGE plpgsql IMMUTABLE
    AS $$
DECLARE
  v_ax double precision;
  v_ay double precision;
  v_bx double precision;
  v_by double precision;
  v_dot double precision;
  v_na double precision;
  v_nb double precision;
  v_cosv double precision;
BEGIN
  IF p_origin IS NULL OR p_candidate IS NULL OR p_destination IS NULL THEN
    RETURN 0;
  END IF;

  v_ax := ST_X(p_candidate) - ST_X(p_origin);
  v_ay := ST_Y(p_candidate) - ST_Y(p_origin);

  v_bx := ST_X(p_destination) - ST_X(p_origin);
  v_by := ST_Y(p_destination) - ST_Y(p_origin);

  v_na := sqrt(v_ax * v_ax + v_ay * v_ay);
  v_nb := sqrt(v_bx * v_bx + v_by * v_by);

  IF v_na = 0 OR v_nb = 0 THEN
    RETURN 0;
  END IF;

  v_dot := v_ax * v_bx + v_ay * v_by;
  v_cosv := v_dot / (v_na * v_nb);

  -- اگر کاندید پشت سر جهت کلی مقصد باشد، پنالتی می‌گیرد
  IF v_cosv < 0 THEN
    RETURN abs(v_cosv) * p_penalty_m;
  END IF;

  RETURN 0;
END;
$$;


--
-- Name: fn_i18n_label(text, bigint, text, public.lang_enum, public.lang_enum); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_i18n_label(p_entity_table text, p_entity_id bigint, p_field text, p_lang public.lang_enum, p_fallback public.lang_enum DEFAULT 'en'::public.lang_enum) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_txt TEXT;
BEGIN
  SELECT txt INTO v_txt
  FROM i18n_texts
  WHERE entity_table = p_entity_table
    AND entity_id    = p_entity_id
    AND field        = p_field
    AND lang         = p_lang
  LIMIT 1;

  IF v_txt IS NOT NULL THEN RETURN v_txt; END IF;

  SELECT txt INTO v_txt
  FROM i18n_texts
  WHERE entity_table = p_entity_table
    AND entity_id    = p_entity_id
    AND field        = p_field
    AND lang         = p_fallback
  LIMIT 1;

  IF v_txt IS NOT NULL THEN RETURN v_txt; END IF;

  SELECT txt INTO v_txt
  FROM i18n_texts
  WHERE entity_table = p_entity_table
    AND entity_id    = p_entity_id
    AND field        = p_field
  ORDER BY lang
  LIMIT 1;

  RETURN v_txt;
END;
$$;


--
-- Name: fn_intra_area_edge_kind(bigint, public.geometry, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_intra_area_edge_kind(p_area_id bigint, p_line public.geometry, p_tol_m double precision DEFAULT 0.35) RETURNS text
    LANGUAGE sql STABLE
    AS $$
  SELECT
    CASE
      WHEN public.fn_route_line_valid_inside_area(p_area_id, p_line, p_tol_m)
        THEN 'safe_direct'
      ELSE 'unsafe_direct_fallback'
    END;
$$;


--
-- Name: fn_landmark_places_json(public.lang_enum, integer, double precision, double precision, bigint, text, boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_landmark_places_json(p_lang public.lang_enum, p_limit integer, p_lat double precision DEFAULT NULL::double precision, p_lon double precision DEFAULT NULL::double precision, p_poi_id bigint DEFAULT NULL::bigint, p_search text DEFAULT NULL::text, p_featured boolean DEFAULT false) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_user_geom_32640 geometry;
BEGIN
    -- تبدیل موقعیت کاربر
    IF p_lat IS NOT NULL AND p_lon IS NOT NULL THEN
        v_user_geom_32640 := ST_Transform(
            ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326),
            32640
        );
    ELSE
        v_user_geom_32640 := NULL;
    END IF;

    RETURN (
        WITH landmarks AS (
            SELECT
                p.id,
                p.geom,
                p.floor,
                p.attrs,
                p.updated_at,

                -- عنوان
                fn_i18n_label('poi_points', p.id, 'name', p_lang, 'fa'::lang_enum) AS title,

                -- آدرس (مثلاً نام صحن)
                fn_i18n_label('areas', cour.id, 'name', p_lang, 'fa'::lang_enum) AS address,

                -- مختصات WGS84
                ST_Y(ST_Transform(p.geom, 4326)) AS lat,
                ST_X(ST_Transform(p.geom, 4326)) AS lon,

                -- فاصله از کاربر (متر)
                CASE
                    WHEN v_user_geom_32640 IS NOT NULL THEN
                        round(ST_Distance(v_user_geom_32640, p.geom))::integer
                    ELSE
                        NULL
                END AS distance_m,

                -- زمان تخمینی پیاده‌روی (دقیقه)
                CASE
                    WHEN v_user_geom_32640 IS NOT NULL THEN
                        GREATEST(
                            1,
                            round(
                                (ST_Distance(v_user_geom_32640, p.geom))::numeric
                                / 70.0
                            )::integer
                        )
                    ELSE
                        NULL
                END AS time_min,

                -- rating / views از attrs
                COALESCE((p.attrs->>'rating')::numeric, 0)  AS rating,
                COALESCE((p.attrs->>'views')::integer, 0)   AS views,

                -- تصویر
                -- تصویر (پشتیبانی همزمان از URL و Base64)
COALESCE(
    NULLIF(p.attrs->>'image_url',''),

    -- اگر media به صورت url/src ذخیره شده باشد (آپلودی)
    NULLIF(ct.media::jsonb->0->>'path',''),
    NULLIF(ct.media::jsonb->0->>'src',''),

    -- اگر media به صورت base64 ذخیره شده باشد
    CASE
        WHEN ct.media IS NOT NULL
         AND (ct.media::jsonb->0 ? 'data')
         AND (ct.media::jsonb->0->>'data') <> ''
        THEN
            'data:' ||
            COALESCE(ct.media::jsonb->0->>'mime','application/octet-stream') ||
            ';base64,' ||
            (ct.media::jsonb->0->>'data')
        ELSE
            NULL
    END
) AS image,


                -- گروه اصلی (مثلاً sahn, riwaq, elevator, ...)
COALESCE(
    p.attrs->>'group',
    cat.level1_code,
    m.default_group,
    'poi'
) AS group_code,

-- زیرگروه (مثلاً sahn, eyvan, ... یا نوع POI)
COALESCE(
    p.attrs->>'sub_group',
    cat.level2_code,
    m.default_subgroup,
    p.poi_type::text
) AS sub_group,

-- subGroupValue (برچسب یکتای زیرگروه / برگ)
COALESCE(
    p.attrs->>'sub_group_value',
    cat.leaf_code,
    'poi-' || p.id::text
) AS sub_group_value,

                -- 👇 فیلدهای محتوای فرهنگی از جدول contents
                ct.title AS content_title,
                ct.body  AS content_body,
                ct.media AS content_media

            FROM poi_points p
						
LEFT JOIN feature_group_mappings m
  ON m.entity_table = 'poi_points'
 AND m.feature_key  = p.poi_type::text
LEFT JOIN categories c_leaf
  ON c_leaf.id = m.category_leaf_id
LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat
  ON TRUE


            -- پیدا کردن area شامل این POI
            LEFT JOIN LATERAL (
                SELECT a.id
                FROM areas a
                WHERE a.floor = p.floor
                  AND ST_Contains(a.geom, p.geom)
                ORDER BY a.id
                LIMIT 1
            ) cour ON TRUE

            -- آوردن content مرتبط با این POI و زبان کاربر
            LEFT JOIN LATERAL (
                SELECT c.*
                FROM contents c
                WHERE c.poi_id = p.id
                  AND c.lang   = p_lang
                ORDER BY c.id
                LIMIT 1
            ) ct ON TRUE

            WHERE
                -- فیلتر اختیاری بر اساس poi_id
                (p_poi_id IS NULL OR p.id = p_poi_id)
                -- تعریف Landmark: هر چیزی که محتوای فرهنگی دارد
                AND (
                    p.has_content = TRUE
                    OR ct.id IS NOT NULL      -- اگر ردیف contents دارد
                )
        ),
				featured AS (
  SELECT l.*
  FROM public.featured_landmark_places f
  JOIN landmarks l ON l.id = f.poi_id
  WHERE f.is_active = true
  ORDER BY f.sort_order, f.id
  LIMIT COALESCE(p_limit, 20)
),

filtered AS (
  SELECT *
  FROM landmarks
  WHERE
    p_search IS NULL
    OR btrim(p_search) = ''
    OR title ILIKE ('%' || p_search || '%')
--     OR COALESCE(address,'') ILIKE ('%' || p_search || '%')
--     OR COALESCE(content_title,'') ILIKE ('%' || p_search || '%')
--     OR COALESCE(content_body,'') ILIKE ('%' || p_search || '%')
),

limited AS (
  SELECT *
  FROM filtered
  ORDER BY
    (distance_m IS NULL),
    distance_m NULLS LAST,
    id
  LIMIT COALESCE(
    CASE WHEN p_poi_id IS NOT NULL THEN 1 ELSE p_limit END,
    20
  )
),

picked AS (
  SELECT * FROM featured WHERE p_featured = true
  UNION ALL
  SELECT * FROM limited  WHERE p_featured = false
)

        SELECT jsonb_build_object(
            'places', jsonb_build_object(
                'landmarkPlaces',
                COALESCE(
                    jsonb_agg(
                        jsonb_build_object(
                            'id',           id::text,
                            'title',        title,
                            'address',      address,
                            'distance',     distance_m,
                            'time',         time_min,
                            'rating',       rating,
                            'views',        views,
                            'image',        image,
														-- 👇 فیلدهای دسته‌بندی برای فیلتر نقشه
                    'group',        group_code,
                    'subGroup',     sub_group,
                            'coordinates',  jsonb_build_array(lat, lon),
                            'subGroupValue', sub_group_value,
                            'lastUpdated',  updated_at,

                            -- 👇 بلوک محتوای فرهنگی
                            'content', CASE
                                WHEN content_title IS NOT NULL
                                  OR content_body  IS NOT NULL
                                  OR content_media IS NOT NULL
                                THEN jsonb_build_object(
                                    'title', content_title,
                                    'body',  content_body,
                                    'media', content_media
                                )
                                ELSE NULL
                            END
                        )
                    ),
                    '[]'::jsonb
                )
            ),
            'language',   p_lang::text,
            'generatedAt', now()
        )
        FROM picked
    );
END;
$$;


--
-- Name: fn_landmark_places_json_new(public.lang_enum, integer, double precision, double precision, bigint, text, boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_landmark_places_json_new(p_lang public.lang_enum, p_limit integer, p_lat double precision DEFAULT NULL::double precision, p_lon double precision DEFAULT NULL::double precision, p_poi_id bigint DEFAULT NULL::bigint, p_search text DEFAULT NULL::text, p_featured boolean DEFAULT false) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_user_geom_32640 geometry;
BEGIN
    IF p_lat IS NOT NULL AND p_lon IS NOT NULL THEN
        v_user_geom_32640 := ST_Transform(
            ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326),
            32640
        );
    ELSE
        v_user_geom_32640 := NULL;
    END IF;

    RETURN (
        WITH landmarks AS (
            SELECT
                p.id,
                p.geom,
                p.floor,
                p.attrs,
                p.updated_at,

                fn_i18n_label('poi_points', p.id, 'name', p_lang, 'fa'::lang_enum) AS title,

                fn_i18n_label('areas', cour.id, 'name', p_lang, 'fa'::lang_enum) AS address,

                ST_Y(ST_Transform(p.geom, 4326)) AS lat,
                ST_X(ST_Transform(p.geom, 4326)) AS lon,

                CASE
                    WHEN v_user_geom_32640 IS NOT NULL THEN round(ST_Distance(v_user_geom_32640, p.geom))::integer
                    ELSE NULL
                END AS distance_m,

                CASE
                    WHEN v_user_geom_32640 IS NOT NULL THEN
                        GREATEST(1, round((ST_Distance(v_user_geom_32640, p.geom))::numeric / 70.0)::integer)
                    ELSE NULL
                END AS time_min,

                COALESCE((p.attrs->>'rating')::numeric, 0)  AS rating,
                COALESCE((p.attrs->>'views')::integer, 0)   AS views,

                COALESCE(
                    p.attrs->>'image_url',
                    CASE
                        WHEN ct.media IS NOT NULL THEN
                            'data:' || (ct.media::jsonb->0->>'mime') || ';base64,' || (ct.media::jsonb->0->>'data')
                        ELSE NULL
                    END
                ) AS image,

                COALESCE(p.attrs->>'group', cat.level1_code, m.default_group, 'poi') AS group_code,
                COALESCE(p.attrs->>'sub_group', cat.level2_code, m.default_subgroup, p.poi_type::text) AS sub_group,
                COALESCE(p.attrs->>'sub_group_value', cat.leaf_code, 'poi-' || p.id::text) AS sub_group_value,

                m.category_leaf_id AS categories_leaf_id,

                ct.title AS content_title,
                ct.body  AS content_body,
                ct.media AS content_media

            FROM poi_points p

            LEFT JOIN feature_group_mappings m
              ON m.entity_table = 'poi_points'
             AND m.feature_key  = p.poi_type::text
            LEFT JOIN categories c_leaf
              ON c_leaf.id = m.category_leaf_id
            LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat
              ON TRUE

            LEFT JOIN LATERAL (
                SELECT a.id
                FROM areas a
                WHERE a.floor = p.floor
                  AND ST_Contains(a.geom, p.geom)
                ORDER BY a.id
                LIMIT 1
            ) cour ON TRUE

            LEFT JOIN LATERAL (
                SELECT c.*
                FROM contents c
                WHERE c.poi_id = p.id
                  AND c.lang   = p_lang
                ORDER BY c.id
                LIMIT 1
            ) ct ON TRUE

            WHERE
                (p_poi_id IS NULL OR p.id = p_poi_id)
                AND (p.has_content = TRUE OR ct.id IS NOT NULL)
        ),
				featured AS (
  SELECT l.*
  FROM public.featured_landmark_places f
  JOIN landmarks l ON l.id = f.poi_id
  WHERE f.is_active = true
  ORDER BY f.sort_order, l.id
  LIMIT COALESCE(p_limit, 20)
),

        filtered AS (
            SELECT *
            FROM landmarks
            WHERE
                p_search IS NULL
                OR btrim(p_search) = ''
                OR title ILIKE ('%' || p_search || '%')
                OR COALESCE(address,'') ILIKE ('%' || p_search || '%')
                OR COALESCE(content_title,'') ILIKE ('%' || p_search || '%')
                OR COALESCE(content_body,'') ILIKE ('%' || p_search || '%')
        ),

        limited_normal AS (
  SELECT *
  FROM filtered
  ORDER BY (distance_m IS NULL), distance_m NULLS LAST, id
  LIMIT COALESCE(CASE WHEN p_poi_id IS NOT NULL THEN 1 ELSE p_limit END, 20)
),
final_rows AS (
  SELECT * FROM featured      WHERE p_featured = true
  UNION ALL
  SELECT * FROM limited_normal WHERE p_featured = false
)


        SELECT jsonb_build_object(
            'places', jsonb_build_object(
                'landmarkPlaces',
                COALESCE(
                    jsonb_agg(
                        jsonb_build_object(
                            'id',           id::bigint,
                            'title',        title,
                            'name',         title,

                            'subGroup',     sub_group,
                            'subGroupValue',sub_group_value,

                            'image',        CASE WHEN image IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(image) END,
                            'images',       CASE WHEN image IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(image) END,
                            'img',          CASE WHEN image IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(image) END,

                            'geo',          jsonb_build_object('lat', lat, 'lng', lon),
                            'location',     jsonb_build_object('lat', lat, 'lng', lon),
                            'coordinates',  jsonb_build_array(lat, lon),
                            'geometry',     jsonb_build_object('type','Point','coordinates', jsonb_build_array(lon, lat)),

                            'address',      address,
                            'description',  content_body,
                            'content',      jsonb_build_object('body', content_body),

                            'category_id',         categories_leaf_id,
                            'categories_leaf_id',  categories_leaf_id
                        )
                    ),
                    '[]'::jsonb
                )
            ),
            'language',   p_lang::text,
            'generatedAt', now()
        )
        FROM final_rows
    );
END;
$$;


--
-- Name: fn_landmark_view_image(public.lang_enum, double precision, double precision, double precision, smallint, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_landmark_view_image(p_lang public.lang_enum, p_lat double precision, p_lon double precision, p_heading_deg double precision, p_floor smallint DEFAULT NULL::smallint, p_fov_deg double precision DEFAULT 45, p_max_distance_m double precision DEFAULT 150) RETURNS jsonb
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    v_user_32640        geometry(Point,32640);
    v_row               record;

    v_side              text;
    v_media_match       jsonb;
    v_media_fallback    jsonb;
BEGIN
    IF p_lat IS NULL OR p_lon IS NULL OR p_heading_deg IS NULL THEN
        RETURN jsonb_build_object(
            'status','INVALID_INPUT',
            'message','lat/lng/heading الزامی است'
        );
    END IF;

    -- کاربر: WGS84 -> 32640
    v_user_32640 := ST_Transform(ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326), 32640);

    /*
      انتخاب:
      - POI هایی که در فاصله p_max_distance هستند
      - POI هایی که در مخروط دید هستند (angle_diff <= fov/2)
      - رنکینگ: angle_diff کمتر (جلوی دیدتر)، سپس distance کمتر
      نکته مهم: SRID-safe => POI هم به 32640 تبدیل می‌شود (اگر SRID=0 => حذف می‌شود)
    */
    WITH candidates AS (
    SELECT
        p.id AS poi_id,
        p.floor,

        CASE
          WHEN ST_SRID(p.geom) = 0 THEN NULL
          ELSE ST_Transform(p.geom, 32640)
        END AS poi_geom_32640,

        c.title AS content_title,
        c.body  AS content_body,
        c.media AS content_media

    FROM poi_points p
    JOIN contents c
      ON c.poi_id = p.id
     AND c.lang   = p_lang

    WHERE
        (p_floor IS NULL OR p.floor = p_floor)

        -- فقط POIهایی که واقعاً حداقل یک تصویر معتبر دارند
        AND jsonb_typeof(COALESCE(c.media, '[]'::jsonb)) = 'array'
        AND EXISTS (
            SELECT 1
            FROM jsonb_array_elements(COALESCE(c.media, '[]'::jsonb)) AS elem
            WHERE COALESCE(elem->>'type', 'image') = 'image'
              AND (
                    NULLIF(elem->>'path', '') IS NOT NULL
                 OR NULLIF(elem->>'url',  '') IS NOT NULL
                 OR NULLIF(elem->>'data', '') IS NOT NULL
              )
        )
),
    candidates2 AS (
        SELECT
            *,
						ST_Distance(v_user_32640, poi_geom_32640) AS distance_exact_m,
            round(ST_Distance(v_user_32640, poi_geom_32640))::int AS distance_m,

            -- bearing از کاربر به POI (deg, 0=north, clockwise) - با mod امن (numeric cast)
            (mod(
                (degrees(ST_Azimuth(v_user_32640, poi_geom_32640)) + 360.0)::numeric,
                360::numeric
            ))::double precision AS bearing_to_poi_deg,

            -- bearing از POI به کاربر (برای تشخیص سمت کاربر نسبت به POI)
            (mod(
                (degrees(ST_Azimuth(poi_geom_32640, v_user_32640)) + 360.0)::numeric,
                360::numeric
            ))::double precision AS bearing_poi_to_user_deg
        FROM candidates
        WHERE poi_geom_32640 IS NOT NULL
          AND ST_DWithin(v_user_32640, poi_geom_32640, p_max_distance_m)
    ),
    scored AS (
        SELECT
            *,
            -- angle diff بین heading و bearing_to_poi - با mod امن (numeric cast)
            abs(
                (mod(
                    (bearing_to_poi_deg - p_heading_deg + 540.0)::numeric,
                    360::numeric
                )::double precision) - 180.0
            ) AS angle_diff_deg
        FROM candidates2
    ),
    filtered AS (
        SELECT *
        FROM scored
        WHERE angle_diff_deg <= (p_fov_deg / 2.0)
        ORDER BY distance_exact_m ASC, angle_diff_deg ASC
        LIMIT 1
    )
    SELECT * INTO v_row FROM filtered;

    IF v_row.poi_id IS NULL THEN
        RETURN jsonb_build_object(
            'status','NO_LANDMARK_IN_VIEW',
            'message','هیچ لندمارکی در مخروط دید کاربر یافت نشد',
            'fov_deg', p_fov_deg,
            'max_distance_m', p_max_distance_m
        );
    END IF;

    -- تعیین orientation مناسب بر اساس اینکه کاربر از کدام سمت POI قرار دارد
    -- (bearing از POI به کاربر)
    -- ✅ north سیستم = north واقعی + 30° (به شرق / ساعتگرد)
-- پس برای تعیین جهت‌ها، bearing را 30° کم می‌کنیم.
DECLARE
  v_bearing_adj double precision;
BEGIN
  v_bearing_adj := (mod((v_row.bearing_poi_to_user_deg - 30.0 + 360.0)::numeric, 360::numeric))::double precision;

  IF v_bearing_adj >= 45 AND v_bearing_adj < 135 THEN
      v_side := 'east';
  ELSIF v_bearing_adj >= 135 AND v_bearing_adj < 225 THEN
      v_side := 'south';
  ELSIF v_bearing_adj >= 225 AND v_bearing_adj < 315 THEN
      v_side := 'west';
  ELSE
      v_side := 'north';
  END IF;
END;

    -- انتخاب تصویر مطابق orientation (اولین تصویر matching)
    SELECT elem
      INTO v_media_match
    FROM jsonb_array_elements(COALESCE(v_row.content_media, '[]'::jsonb)) AS elem
    WHERE COALESCE(elem->>'type','image') = 'image'
      AND lower(COALESCE(elem->>'orientation','')) = v_side
    LIMIT 1;

    -- fallback: اولین image موجود
    SELECT elem
      INTO v_media_fallback
    FROM jsonb_array_elements(COALESCE(v_row.content_media, '[]'::jsonb)) AS elem
    WHERE COALESCE(elem->>'type','image') = 'image'
    LIMIT 1;

    RETURN jsonb_build_object(
        'status', 'OK',
        'poi_id', v_row.poi_id,
        'floor',  v_row.floor,

        'distance_m', v_row.distance_m,
        'bearing_to_poi_deg', v_row.bearing_to_poi_deg,
        'angle_diff_deg', v_row.angle_diff_deg,

        'selected_orientation', v_side,

        'content', jsonb_build_object(
            'title', v_row.content_title,
            'body',  v_row.content_body
        ),

        'image', COALESCE(v_media_match, v_media_fallback),
        'imageMatched', (v_media_match IS NOT NULL)
    );
END;
$$;


--
-- Name: fn_log_route_debug(text, text, public.gender_enum, smallint, public.geometry, public.geometry, bigint, bigint, bigint, text, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_log_route_debug(p_algo text, p_mode text, p_gender public.gender_enum, p_floor smallint, p_origin_geom public.geometry, p_dest_geom public.geometry, p_origin_node bigint, p_dest_node bigint, p_last_node bigint, p_status text, p_details jsonb) RETURNS bigint
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_last_geom  geometry(Point,32640);
  v_id         bigint;
BEGIN
  IF p_last_node IS NOT NULL THEN
    SELECT n.geom
    INTO   v_last_geom
    FROM   routing_nodes n
    WHERE  n.id = p_last_node;
  END IF;

  INSERT INTO route_logs_debug(
    algo, mode, gender, floor,
    origin_geom, dest_geom,
    origin_node_id, dest_node_id, last_node_id, last_node_geom,
    status, details
  )
  VALUES(
    p_algo, p_mode, p_gender, p_floor,
    p_origin_geom, p_dest_geom,
    p_origin_node, p_dest_node, p_last_node, v_last_geom,
    p_status, p_details
  )
  RETURNING id INTO v_id;

  RETURN v_id;
END;
$$;


--
-- Name: fn_map_features(public.lang_enum); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_map_features(p_lang public.lang_enum) RETURNS TABLE(entity_table text, entity_id bigint, geom_4326 public.geometry, floor smallint, name text, description text, "group" text, "subGroup" text, "subGroupValue" text, category_level3 text, category_level4 text, category_level5 text, category_leaf text, types text[], services jsonb, gender text, nodefunction text, restrictedtimes jsonb, latitude double precision, longitude double precision, gpsmeta jsonb, "timestamp" timestamp with time zone, transportmodes text[])
    LANGUAGE sql
    AS $$
  -- ========== 1) POI ها ==========
  SELECT
    'poi_points'::text AS entity_table,
    p.id               AS entity_id,
    ST_Transform(p.geom, 4326) AS geom_4326,
    p.floor,
    fn_i18n_label('poi_points', p.id, 'name', p_lang, 'fa') AS name,
    fn_i18n_label('poi_points', p.id, 'desc', p_lang, 'fa') AS description,

    COALESCE(
      p.attrs->>'group',
      cat.level1_code,
      m.default_group,
      'poi'
    ) AS "group",

    COALESCE(
      p.attrs->>'sub_group',
      cat.level2_code,
      m.default_subgroup,
      p.poi_type::text
    ) AS "subGroup",

    COALESCE(
      p.attrs->>'sub_group_value',
      cat.leaf_code,
      'poi-' || p.id
    ) AS "subGroupValue",

    cat.level3_code AS category_level3,
    cat.level4_code AS category_level4,
    cat.level5_code AS category_level5,
    cat.leaf_code   AS category_leaf,

    COALESCE(
      ARRAY(
        SELECT jsonb_array_elements_text(p.attrs->'types')
      ),
      m.default_types,
      ARRAY[]::text[]
    ) AS types,

    COALESCE(
      p.attrs->'services',
      m.default_services,
      '{}'::jsonb
    ) AS services,

    CASE
      WHEN p.attrs ? 'gender' THEN
        CASE p.attrs->>'gender'
          WHEN 'male'   THEN 'male'
          WHEN 'female' THEN 'female'
          ELSE 'both'
        END
      WHEN m.default_gender IS NOT NULL THEN m.default_gender
      ELSE 'both'
    END AS gender,

    COALESCE(
      p.attrs->>'nodeFunction',
      m.default_node_function,
      'poi'
    ) AS nodeFunction,

    COALESCE(
      p.attrs->'restrictedTimes',
      '[]'::jsonb
    ) AS restrictedTimes,

    ST_Y(ST_Transform(p.geom, 4326)) AS latitude,
    ST_X(ST_Transform(p.geom, 4326)) AS longitude,

    p.attrs->'gpsMeta' AS gpsMeta,

    now() AS "timestamp",

    COALESCE(
      ARRAY(
        SELECT jsonb_array_elements_text(p.attrs->'transportModes')
      ),
      m.default_transport_modes,
      ARRAY[]::text[]
    ) AS transportModes

  FROM poi_points p
  LEFT JOIN feature_group_mappings m
    ON m.entity_table = 'poi_points'
   AND m.feature_key  = p.poi_type::text
  LEFT JOIN categories c_leaf
    ON c_leaf.id = m.category_leaf_id
  LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat
    ON TRUE

  UNION ALL

  -- ========== 2) درب‌ها ==========
  SELECT
    'doors'::text AS entity_table,
    d.id          AS entity_id,
    ST_Transform(d.geom, 4326) AS geom_4326,
    d.floor,
    fn_i18n_label('doors', d.id, 'name', p_lang, 'fa') AS name,
    fn_i18n_label('doors', d.id, 'desc', p_lang, 'fa') AS description,

    COALESCE(
      d.attrs->>'group',
      cat.level1_code,
      m.default_group,
      'connection'
    ) AS "group",

    COALESCE(
      d.attrs->>'sub_group',
      cat.level2_code,
      m.default_subgroup,
      'door'
    ) AS "subGroup",

    COALESCE(
      d.attrs->>'sub_group_value',
      cat.leaf_code,
      'door-' || d.id
    ) AS "subGroupValue",

    cat.level3_code AS category_level3,
    cat.level4_code AS category_level4,
    cat.level5_code AS category_level5,
    cat.leaf_code   AS category_leaf,

    COALESCE(
      ARRAY(
        SELECT jsonb_array_elements_text(d.attrs->'types')
      ),
      m.default_types,
      ARRAY['door']::text[]
    ) AS types,

    jsonb_build_object(
      'wheelchair',   'wheelchair' = ANY (d.modes),
      'electricVan',  'van'        = ANY (d.modes),
      'walking',      'walk'       = ANY (d.modes)
    ) AS services,

    COALESCE(
      CASE d.allowed_gender
        WHEN 'male'   THEN 'male'
        WHEN 'female' THEN 'female'
        ELSE 'both'
      END,
      m.default_gender,
      'both'
    ) AS gender,

    COALESCE(
      d.attrs->>'nodeFunction',
      m.default_node_function,
      'door'
    ) AS nodeFunction,

    COALESCE(
      d.attrs->'restrictedTimes',
      '[]'::jsonb
    ) AS restrictedTimes,

    ST_Y(ST_Transform(ST_LineInterpolatePoint(d.geom, 0.5), 4326)) AS latitude,
    ST_X(ST_Transform(ST_LineInterpolatePoint(d.geom, 0.5), 4326)) AS longitude,

    d.attrs->'gpsMeta' AS gpsMeta,

    now() AS "timestamp",

    d.modes AS transportModes

  FROM doors d
  LEFT JOIN feature_group_mappings m
    ON m.entity_table = 'doors'
   AND m.feature_key  = 'door'
  LEFT JOIN categories c_leaf
    ON c_leaf.id = m.category_leaf_id
  LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat
    ON TRUE

  UNION ALL

  -- ========== 3) Areas (با استفاده از areas_simplified) ==========
  SELECT
    'areas'::text AS entity_table,
    a.id          AS entity_id,
    ST_Transform(COALESCE(s.geom, a.geom), 4326) AS geom_4326,
    a.floor,
    fn_i18n_label('areas', a.id, 'name', p_lang, 'fa') AS name,
    fn_i18n_label('areas', a.id, 'desc', p_lang, 'fa') AS description,

    COALESCE(
      a.attrs->>'group',
      cat.level1_code,
      m.default_group,
      CASE a.area_type
        WHEN 'courtyard'     THEN 'sahn'
        WHEN 'riwaq'         THEN 'riwaq'
        WHEN 'iwan'          THEN 'iwan'
        WHEN 'mosque'        THEN 'mosque'
        WHEN 'elevator_area' THEN 'vertical'
        WHEN 'stair_area'    THEN 'vertical'
        WHEN 'ramp_area'     THEN 'vertical'
        WHEN 'admin_zone'    THEN 'admin'
        ELSE 'area'
      END,
      'area'
    ) AS "group",

    COALESCE(
      a.attrs->>'sub_group',
      cat.level2_code,
      m.default_subgroup,
      fn_i18n_label('areas', a.id, 'name', p_lang, 'fa')
    ) AS "subGroup",

    COALESCE(
      a.attrs->>'sub_group_value',
      cat.leaf_code,
      'area-' || a.id
    ) AS "subGroupValue",

    cat.level3_code AS category_level3,
    cat.level4_code AS category_level4,
    cat.level5_code AS category_level5,
    cat.leaf_code   AS category_leaf,

    COALESCE(
      ARRAY(
        SELECT jsonb_array_elements_text(a.attrs->'types')
      ),
      m.default_types,
      ARRAY[a.area_type::text]
    ) AS types,

    COALESCE(
      a.attrs->'services',
      m.default_services,
      '{}'::jsonb
    ) AS services,

    COALESCE(
      CASE a.allowed_gender
        WHEN 'male'   THEN 'male'
        WHEN 'female' THEN 'female'
        ELSE 'both'
      END,
      m.default_gender,
      'both'
    ) AS gender,

    COALESCE(
      a.attrs->>'nodeFunction',
      m.default_node_function,
      'area'
    ) AS nodeFunction,

    COALESCE(
      a.attrs->'restrictedTimes',
      '[]'::jsonb
    ) AS restrictedTimes,

    ST_Y(
      ST_Centroid(
        ST_Transform(COALESCE(s.geom, a.geom), 4326)
      )
    ) AS latitude,
    ST_X(
      ST_Centroid(
        ST_Transform(COALESCE(s.geom, a.geom), 4326)
      )
    ) AS longitude,

    a.attrs->'gpsMeta' AS gpsMeta,

    now() AS "timestamp",

    COALESCE(
      ARRAY(
        SELECT jsonb_array_elements_text(a.attrs->'transportModes')
      ),
      m.default_transport_modes,
      ARRAY[]::text[]
    ) AS transportModes

  FROM areas a
  LEFT JOIN areas_simplified s
    ON s.id = a.id
  LEFT JOIN feature_group_mappings m
    ON m.entity_table = 'areas'
   AND m.feature_key  = a.area_type::text
  LEFT JOIN categories c_leaf
    ON c_leaf.id = m.category_leaf_id
  LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat
    ON TRUE

  UNION ALL

  -- ========== 4) Van nodes ==========
  SELECT
    'van_nodes'::text AS entity_table,
    v.id              AS entity_id,
    ST_Transform(v.geom, 4326) AS geom_4326,
    v.floor,
    fn_i18n_label('van_nodes', v.id, 'name', p_lang, 'fa') AS name,
    fn_i18n_label('van_nodes', v.id, 'desc', p_lang, 'fa') AS description,

    COALESCE(
      cat.level1_code,
      m.default_group,
      'van'
    ) AS "group",

    COALESCE(
      cat.level2_code,
      m.default_subgroup,
      CASE v.node_type
        WHEN 'stop'     THEN 'van-stop'
        WHEN 'junction' THEN 'van-junction'
        ELSE 'van-node'
      END
    ) AS "subGroup",

    COALESCE(
      cat.leaf_code,
      ('van-node-' || v.id)::text
    ) AS "subGroupValue",

    cat.level3_code AS category_level3,
    cat.level4_code AS category_level4,
    cat.level5_code AS category_level5,
    cat.leaf_code   AS category_leaf,

    COALESCE(
      m.default_types,
      ARRAY[v.node_type::text]
    ) AS types,

    COALESCE(
      m.default_services,
      jsonb_build_object(
        'wheelchair',   true,
        'electricVan',  (v.node_type = 'stop'),
        'walking',      true
      )
    ) AS services,

    COALESCE(
      m.default_gender,
      'both'
    ) AS gender,

    COALESCE(
      m.default_node_function,
      'van_node'
    ) AS nodeFunction,

    '[]'::jsonb AS restrictedTimes,

    ST_Y(ST_Transform(v.geom, 4326)) AS latitude,
    ST_X(ST_Transform(v.geom, 4326)) AS longitude,

    NULL::jsonb AS gpsMeta,

    now() AS "timestamp",

    COALESCE(
      m.default_transport_modes,
      ARRAY['van']::text[]
    ) AS transportModes

  FROM van_nodes v
  LEFT JOIN feature_group_mappings m
    ON m.entity_table = 'van_nodes'
   AND m.feature_key  = v.node_type::text
  LEFT JOIN categories c_leaf
    ON c_leaf.id = m.category_leaf_id
  LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat
    ON TRUE

  UNION ALL

  -- ========== 5) QR codes ==========
  SELECT
    'qrcodes'::text AS entity_table,
    q.id            AS entity_id,
    ST_Transform(q.geom, 4326) AS geom_4326,
    COALESCE((q.attrs->>'floor')::smallint, 0) AS floor,

    fn_i18n_label('qrcodes', q.id, 'name', p_lang, 'fa') AS name,
    fn_i18n_label('qrcodes', q.id, 'desc', p_lang, 'fa') AS description,

    COALESCE(
      q.attrs->>'group',
      cat.level1_code,
      m.default_group,
      'qrcode'
    ) AS "group",

    COALESCE(
      q.attrs->>'sub_group',
      cat.level2_code,
      m.default_subgroup,
      q.target_type::text
    ) AS "subGroup",

    COALESCE(
      q.attrs->>'sub_group_value',
      cat.leaf_code,
      q.code
    ) AS "subGroupValue",

    cat.level3_code AS category_level3,
    cat.level4_code AS category_level4,
    cat.level5_code AS category_level5,
    cat.leaf_code   AS category_leaf,

    COALESCE(
      ARRAY(
        SELECT jsonb_array_elements_text(q.attrs->'types')
      ),
      m.default_types,
      ARRAY[q.target_type::text]
    ) AS types,

    COALESCE(
      q.attrs->'services',
      m.default_services,
      '{}'::jsonb
    ) AS services,

    CASE
      WHEN q.attrs ? 'gender' THEN
        CASE q.attrs->>'gender'
          WHEN 'male'   THEN 'male'
          WHEN 'female' THEN 'female'
          ELSE 'both'
        END
      WHEN m.default_gender IS NOT NULL THEN m.default_gender
      ELSE 'both'
    END AS gender,

    COALESCE(
      q.attrs->>'nodeFunction',
      m.default_node_function,
      'qrcode'
    ) AS nodeFunction,

    COALESCE(
      q.attrs->'restrictedTimes',
      '[]'::jsonb
    ) AS restrictedTimes,

    ST_Y(ST_Transform(q.geom, 4326)) AS latitude,
    ST_X(ST_Transform(q.geom, 4326)) AS longitude,

    q.attrs->'gpsMeta' AS gpsMeta,

    now() AS "timestamp",

    COALESCE(
      ARRAY(
        SELECT jsonb_array_elements_text(q.attrs->'transportModes')
      ),
      m.default_transport_modes,
      ARRAY[]::text[]
    ) AS transportModes

  FROM qrcodes q
  LEFT JOIN feature_group_mappings m
    ON m.entity_table = 'qrcodes'
   AND m.feature_key  = q.target_type::text
  LEFT JOIN categories c_leaf
    ON c_leaf.id = m.category_leaf_id
  LEFT JOIN LATERAL fn_category_path(c_leaf.id) cat
    ON TRUE
$$;


--
-- Name: fn_map_features_mvt(integer, integer, integer, public.lang_enum, smallint, text, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_map_features_mvt(z integer, x integer, y integer, p_lang public.lang_enum DEFAULT 'fa'::public.lang_enum, p_floor smallint DEFAULT NULL::smallint, p_gender text DEFAULT NULL::text, p_entity_tables text DEFAULT NULL::text) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857   geometry;
  tile_bbox_4326   geometry;
  v_entity_tables  text[];
BEGIN
  -- BBOX tile در WebMercator
  tile_bbox_3857 := ST_TileEnvelope(z, x, y);
  tile_bbox_4326 := ST_Transform(tile_bbox_3857, 4326);

  -- لیست لایه‌ها (entity_table) اگر داده شده
  IF p_entity_tables IS NOT NULL AND p_entity_tables <> '' THEN
    v_entity_tables := string_to_array(p_entity_tables, ',');
  ELSE
    v_entity_tables := NULL;
  END IF;

  RETURN (
    SELECT ST_AsMVT(tile, 'map_features', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(f.geom_4326, 3857),
          tile_bbox_3857,
          4096,
          64,
          true
        ) AS geom,

        f.entity_table,
        f.entity_id,
        f.floor,
        f.name,
        f.description,
        f."group",
        f."subGroup",
        f."subGroupValue",
        f.category_level3,
        f.category_level4,
        f.category_level5,
        f.category_leaf,
        f.types,
        f.services,
        f.gender,
        f.nodefunction,
        f.restrictedtimes,
        f.latitude,
        f.longitude,
        f.gpsmeta,
        f."timestamp",
        f.transportmodes

      FROM public.fn_map_features(p_lang) AS f
      WHERE
        f.geom_4326 IS NOT NULL
        AND ST_Intersects(f.geom_4326, tile_bbox_4326)
        AND (p_floor  IS NULL OR f.floor  = p_floor)
        AND (p_gender IS NULL OR f.gender = p_gender)
        AND (v_entity_tables IS NULL OR f.entity_table = ANY (v_entity_tables))
    ) AS tile
    WHERE geom IS NOT NULL
  );
END;
$$;


--
-- Name: fn_map_geojson(public.lang_enum, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_map_geojson(p_lang public.lang_enum, p_floor smallint) RETURNS jsonb
    LANGUAGE sql
    AS $$
  WITH feats AS (
    SELECT
      'Feature' AS type,
      ST_AsGeoJSON(f.geom_4326)::jsonb AS geometry,
      jsonb_build_object(
        -- می‌تونی این id رو در فرانت استفاده کنی؛ اگر نخواستی نادیده بگیر
        'id',             f.entity_table || '-' || f.entity_id,

        -- فیلدهایی که در نمونه داده بودی:
        'name',           f.name,
        'description',    f.description,
        'group',          f."group",
        'subGroup',       f."subGroup",
        'subGroupValue',  f."subGroupValue",
        'types',          f.types,
        'services',       f.services,
        'gender',         f.gender,
        'nodeFunction',   f.nodeFunction,
        'restrictedTimes',f.restrictedTimes,
        'latitude',       f.latitude,
        'longitude',      f.longitude,
        'gpsMeta',        f.gpsMeta,
        'timestamp',      f."timestamp",
        'transportModes', f.transportModes,

        -- اضافه: اگر خواستی در فرانت از روی floor هم فیلتر/سوییچ کنی
        'floor',          f.floor
      ) AS properties
    FROM fn_map_features(p_lang) f
    WHERE f.floor = p_floor
  )
  SELECT jsonb_build_object(
    'type',     'FeatureCollection',
    'features', COALESCE(jsonb_agg(to_jsonb(feats)), '[]'::jsonb)
  )
  FROM feats;
$$;


--
-- Name: fn_move_door_to_click(bigint, double precision, double precision, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_move_door_to_click(p_door_id bigint, p_x double precision, p_y double precision, p_floor smallint DEFAULT NULL::smallint) RETURNS TABLE(door_id bigint, door_geom public.geometry, door_from_area bigint, door_to_area bigint, dap_id bigint, dap_geom public.geometry)
    LANGUAGE plpgsql
    AS $$
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
ORDER BY
  CASE WHEN ST_DWithin(a.geom, v_click_pt, 0.50) THEN 0 ELSE 1 END,
  a.geom <-> v_click_pt,
  ST_Distance(a.geom, v_click_pt)
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
    v_dap_geom := ST_LineInterpolatePoint(v_door_geom, 0.5);

    -- 6) محدوده مقابل
    SELECT a2.id
INTO v_neighbor_area
FROM public.areas a2
WHERE a2.floor = v_door_floor
  AND a2.id <> v_area_id
  AND public.fn_area_routing_role(a2.area_type, a2.attrs) = 'routable'
  AND ST_DWithin(a2.geom, v_dap_geom, 2.0)
ORDER BY
  ST_Distance(a2.geom, v_dap_geom),
  a2.geom <-> v_dap_geom
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
        to_area
    )
    VALUES (
        p_door_id,
        v_dap_geom,
        v_door_floor,
        v_area_id,
        v_neighbor_area
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
$$;


--
-- Name: fn_prayer_event_time(date, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_prayer_event_time(p_date date, p_event text) RETURNS time without time zone
    LANGUAGE sql
    AS $$
  SELECT CASE p_event
    WHEN 'fajr'     THEN pt.fajr
    WHEN 'sunrise'  THEN pt.sunrise
    WHEN 'dhuhr'    THEN pt.dhuhr
    WHEN 'sunset'   THEN pt.sunset
    WHEN 'maghrib'  THEN pt.maghrib
    WHEN 'midnight' THEN pt.midnight
    ELSE NULL
  END
  FROM prayer_times pt
  WHERE pt.d_greg = p_date
  LIMIT 1;
$$;


--
-- Name: fn_rebuild_area_mesh_grid(bigint, smallint, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_rebuild_area_mesh_grid(p_area_id bigint, p_floor smallint, p_step_m double precision DEFAULT 4.0, p_cell_half_m double precision DEFAULT 0.35) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_area geometry;
  v_walk geometry;
  v_xmin double precision;
  v_ymin double precision;
  v_xmax double precision;
  v_ymax double precision;
  v_tri_count integer := 0;
  v_adj_count integer := 0;
BEGIN
  SELECT a.geom
  INTO v_area
  FROM public.areas a
  WHERE a.id = p_area_id
    AND a.floor = p_floor;

  IF v_area IS NULL THEN
    RETURN jsonb_build_object(
      'ok', false,
      'msg', 'area_not_found',
      'area_id', p_area_id,
      'floor', p_floor
    );
  END IF;

  -- کمی بافر منفی برای اینکه نقاط mesh لب مرز نیفتند
  v_walk := ST_Buffer(v_area, -0.20);

  IF v_walk IS NULL OR ST_IsEmpty(v_walk) THEN
    v_walk := v_area;
  END IF;

  v_xmin := ST_XMin(v_walk);
  v_ymin := ST_YMin(v_walk);
  v_xmax := ST_XMax(v_walk);
  v_ymax := ST_YMax(v_walk);

  PERFORM public.fn_clear_area_mesh(p_area_id, p_floor);

  --------------------------------------------------------------------
  -- 1) ساخت node/cellهای mesh داخل area
  -- generate_series با double precision کار نمی‌کند؛ پس numeric cast شده
  --------------------------------------------------------------------
  WITH grid AS (
    SELECT
      ST_SetSRID(
        ST_MakePoint(x::double precision, y::double precision),
        32640
      )::geometry(Point, 32640) AS pt
    FROM generate_series(
      v_xmin::numeric,
      v_xmax::numeric,
      p_step_m::numeric
    ) AS x
    CROSS JOIN generate_series(
      v_ymin::numeric,
      v_ymax::numeric,
      p_step_m::numeric
    ) AS y
  ),
  inside_pts AS (
    SELECT pt
    FROM grid
    WHERE ST_Covers(v_walk, pt)
  ),
  cells AS (
    SELECT
      ST_Buffer(pt, p_cell_half_m, 'quad_segs=1')::geometry(Polygon, 32640) AS geom,
      pt
    FROM inside_pts
  )
  INSERT INTO public.mesh_triangles (geom, floor, area_id, attrs)
  SELECT
    geom,
    p_floor,
    p_area_id,
    jsonb_build_object(
      'mesh_type', 'grid_cell',
      'step_m', p_step_m,
      'cell_half_m', p_cell_half_m,
      'created_at', now()
    )
  FROM cells;

  GET DIAGNOSTICS v_tri_count = ROW_COUNT;

  --------------------------------------------------------------------
  -- 2) اتصال سلول‌های مجاور فقط اگر خط بینشان داخل area باشد
  --------------------------------------------------------------------
  WITH mt AS (
    SELECT
      id,
      ST_PointOnSurface(geom)::geometry(Point, 32640) AS pt
    FROM public.mesh_triangles
    WHERE area_id = p_area_id
      AND floor = p_floor
  ),
  pairs AS (
    SELECT
      a.id AS tri_a,
      b.id AS tri_b,
      a.pt AS pt_a,
      b.pt AS pt_b,
      ST_MakeLine(a.pt, b.pt)::geometry(LineString, 32640) AS seg
    FROM mt a
    JOIN mt b
      ON a.id < b.id
     AND ST_DWithin(a.pt, b.pt, p_step_m * 1.45)
  )
  INSERT INTO public.mesh_adjacency (tri_a, tri_b, door_id, cost_w, gate_point)
  SELECT
    tri_a,
    tri_b,
    NULL::bigint AS door_id,
    1.0::numeric AS cost_w,
    ST_LineInterpolatePoint(seg, 0.5)::geometry(Point, 32640) AS gate_point
  FROM pairs
  WHERE ST_CoveredBy(seg, ST_Buffer(v_area, 0.05))
  ON CONFLICT (tri_a, tri_b) DO NOTHING;

  GET DIAGNOSTICS v_adj_count = ROW_COUNT;

  RETURN jsonb_build_object(
    'ok', true,
    'area_id', p_area_id,
    'floor', p_floor,
    'step_m', p_step_m,
    'mesh_nodes', v_tri_count,
    'mesh_edges', v_adj_count
  );
END;
$$;


--
-- Name: fn_rebuild_door_access_points_v2(smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_rebuild_door_access_points_v2(p_floor smallint) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_inserted integer := 0;
    v_search_m double precision := 2.0;
BEGIN
  --------------------------------------------------------------------
  -- پاکسازی گراف وابسته به DAP همین floor
  --------------------------------------------------------------------
  DELETE FROM public.routing_edge_areas rea
  USING public.routing_edges_static e
  WHERE rea.edge_id = e.id
    AND (
      p_floor IS NULL
      OR e.floor = p_floor
    );

  DELETE FROM public.routing_edges_static e
  WHERE p_floor IS NULL
     OR e.floor = p_floor;

  DELETE FROM public.routing_nodes rn
  WHERE rn.ref_table = 'door_access_points'
    AND (
      p_floor IS NULL
      OR rn.floor = p_floor
    );

  DELETE FROM public.door_access_points dap
  WHERE dap.floor = p_floor;

  WITH door_lines AS (
      SELECT
          d.id AS door_id,
          d.floor,
          ST_LineMerge(d.geom)::geometry(LineString, 32640) AS door_line
      FROM public.doors d
      WHERE d.floor = p_floor
        AND d.geom IS NOT NULL
        AND NOT ST_IsEmpty(d.geom)
        AND GeometryType(ST_LineMerge(d.geom)) = 'LINESTRING'
        AND ST_Length(ST_LineMerge(d.geom)) > 0.05
  ),

  door_basis AS (
      SELECT
          dl.door_id,
          dl.floor,
          dl.door_line,
          ST_LineInterpolatePoint(dl.door_line, 0.5)::geometry(Point, 32640) AS door_mid,
          ST_StartPoint(dl.door_line)::geometry(Point, 32640) AS p1,
          ST_EndPoint(dl.door_line)::geometry(Point, 32640) AS p2
      FROM door_lines dl
  ),

  normals AS (
      SELECT
          b.*,
          (ST_X(b.p2) - ST_X(b.p1)) AS dx,
          (ST_Y(b.p2) - ST_Y(b.p1)) AS dy,
          ST_Length(b.door_line) AS len
      FROM door_basis b
  ),

  normal_segments AS (
      SELECT
          n.door_id,
          n.floor,
          n.door_line,
          n.door_mid,
          (-n.dy / NULLIF(n.len, 0))::double precision AS nx,
          ( n.dx / NULLIF(n.len, 0))::double precision AS ny,
          ST_SetSRID(
              ST_MakeLine(
                  ST_MakePoint(
                      ST_X(n.door_mid) - (-n.dy / NULLIF(n.len, 0)) * v_search_m,
                      ST_Y(n.door_mid) - ( n.dx / NULLIF(n.len, 0)) * v_search_m
                  ),
                  ST_MakePoint(
                      ST_X(n.door_mid) + (-n.dy / NULLIF(n.len, 0)) * v_search_m,
                      ST_Y(n.door_mid) + ( n.dx / NULLIF(n.len, 0)) * v_search_m
                  )
              ),
              32640
          )::geometry(LineString, 32640) AS normal_seg
      FROM normals n
      WHERE n.len > 0
  ),

  --------------------------------------------------------------------
  -- اصلاح اصلی:
  -- محدوده‌های فیزیکی stair/ramp/elevator اصلاً وارد edge_hits نمی‌شوند.
  -- این فیلتر علاوه بر fn_area_routing_role است، چون attrs.routing_role
  -- ممکن است اشتباه override شده باشد.
  --------------------------------------------------------------------
    eligible_edge_areas AS (
      SELECT
          a.id,
          a.floor,
          a.geom,
          a.area_type,
          a.attrs,
          public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb)) AS routing_role
      FROM public.areas a
      WHERE a.floor = p_floor
        AND a.geom IS NOT NULL

        -- حذف سختگیرانه overlayهای فیزیکی
        -- حتی اگر attrs.routing_role اشتباه override شده باشد
        AND a.area_type::text NOT IN (
            'stair_area',
            'ramp_area',
            'elevator_area'
        )

        -- حذف همه transparentهای منطقی دیگر
        AND public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb)) <> 'transparent'
  ),

  edge_hits AS (
      SELECT
          ns.door_id,
          ns.floor,
          ns.door_line,
          ns.door_mid,
          ns.nx,
          ns.ny,
          ns.normal_seg,
          a.id AS edge_area_id,
          a.routing_role AS edge_role,
          ST_ClosestPoint(hit.hit_geom, ns.door_mid)::geometry(Point, 32640) AS dap_geom,
          ST_Distance(ns.door_mid, ST_ClosestPoint(hit.hit_geom, ns.door_mid)) AS door_to_dap_m
      FROM normal_segments ns
      JOIN eligible_edge_areas a
        ON a.floor = ns.floor
       AND ST_Intersects(ST_Boundary(a.geom), ns.normal_seg)

      CROSS JOIN LATERAL (
          SELECT (dp).geom AS hit_geom
          FROM ST_Dump(ST_Intersection(ST_Boundary(a.geom), ns.normal_seg)) AS dp
          WHERE NOT ST_IsEmpty((dp).geom)
      ) hit

      WHERE ST_Distance(ns.door_mid, ST_ClosestPoint(hit.hit_geom, ns.door_mid)) <= v_search_m
  ),

  nearest_edge AS (
      SELECT DISTINCT ON (eh.door_id)
          eh.*
      FROM edge_hits eh
      ORDER BY
          eh.door_id,

          -- اولویت با محدوده routable است، بعد doorstep_only، بعد فاصله.
          -- چون transparent قبلاً حذف شده و دیگر نباید انتخاب شود.
          CASE eh.edge_role
              WHEN 'routable' THEN 1
              WHEN 'doorstep_only' THEN 2
              ELSE 9
          END,
          eh.door_to_dap_m ASC,
          eh.edge_area_id ASC
  ),
	

  side_areas AS (
      SELECT
          ne.*,

          plus_side.area_id  AS plus_area,
          plus_side.role     AS plus_role,
          plus_side.dist_m   AS plus_dist_m,

          minus_side.area_id AS minus_area,
          minus_side.role    AS minus_role,
          minus_side.dist_m  AS minus_dist_m

      FROM nearest_edge ne

      LEFT JOIN LATERAL (
          SELECT
              a.id AS area_id,
              public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb)) AS role,
              s.dist_m
          FROM (
              VALUES
                  (0.05::double precision),
                  (0.10::double precision),
                  (0.20::double precision),
                  (0.35::double precision),
                  (0.50::double precision),
                  (0.75::double precision),
                  (1.00::double precision),
                  (1.25::double precision),
                  (1.50::double precision),
                  (1.75::double precision),
                  (1.95::double precision)
          ) AS s(dist_m)
          JOIN public.areas a
            ON a.floor = ne.floor
           AND a.geom IS NOT NULL
           AND ST_Covers(
                  a.geom,
                  ST_SetSRID(
                      ST_MakePoint(
                          ST_X(ne.dap_geom) + ne.nx * s.dist_m,
                          ST_Y(ne.dap_geom) + ne.ny * s.dist_m
                      ),
                      32640
                  )
               )
          WHERE a.area_type::text NOT IN (
                    'stair_area',
                    'ramp_area',
                    'elevator_area'
                )
            AND public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb)) <> 'transparent'
          ORDER BY
              s.dist_m ASC,
              CASE public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb))
                  WHEN 'routable' THEN 1
                  WHEN 'doorstep_only' THEN 2
                  ELSE 9
              END,
              ST_Area(a.geom) ASC,
              a.id ASC
          LIMIT 1
      ) plus_side ON true

      LEFT JOIN LATERAL (
          SELECT
              a.id AS area_id,
              public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb)) AS role,
              s.dist_m
          FROM (
              VALUES
                  (0.05::double precision),
                  (0.10::double precision),
                  (0.20::double precision),
                  (0.35::double precision),
                  (0.50::double precision),
                  (0.75::double precision),
                  (1.00::double precision),
                  (1.25::double precision),
                  (1.50::double precision),
                  (1.75::double precision),
                  (1.95::double precision)
          ) AS s(dist_m)
          JOIN public.areas a
            ON a.floor = ne.floor
           AND a.geom IS NOT NULL
           AND ST_Covers(
                  a.geom,
                  ST_SetSRID(
                      ST_MakePoint(
                          ST_X(ne.dap_geom) - ne.nx * s.dist_m,
                          ST_Y(ne.dap_geom) - ne.ny * s.dist_m
                      ),
                      32640
                  )
               )
          WHERE a.area_type::text NOT IN (
                    'stair_area',
                    'ramp_area',
                    'elevator_area'
                )
            AND public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb)) <> 'transparent'
          ORDER BY
              s.dist_m ASC,
              CASE public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb))
                  WHEN 'routable' THEN 1
                  WHEN 'doorstep_only' THEN 2
                  ELSE 9
              END,
              ST_Area(a.geom) ASC,
              a.id ASC
          LIMIT 1
      ) minus_side ON true
  ),

  normalized AS (
      SELECT
          sa.*,

          CASE
              WHEN sa.plus_area IS NOT NULL
               AND sa.minus_area IS NOT NULL
               AND sa.plus_area = sa.minus_area
                  THEN sa.plus_area

              WHEN sa.minus_area IS NOT NULL
                  THEN sa.minus_area

              ELSE sa.plus_area
          END AS final_from_area,

          CASE
              WHEN sa.plus_area IS NOT NULL
               AND sa.minus_area IS NOT NULL
               AND sa.plus_area <> sa.minus_area
                  THEN sa.plus_area

              ELSE NULL::bigint
          END AS final_to_area,

          CASE
              WHEN sa.plus_area IS NOT NULL
               AND sa.minus_area IS NOT NULL
               AND sa.plus_area <> sa.minus_area
                  THEN 'two_sided_door'

              WHEN sa.plus_area IS NOT NULL
               AND sa.minus_area IS NOT NULL
               AND sa.plus_area = sa.minus_area
                  THEN 'one_sided_same_routable_area'

              WHEN sa.plus_area IS NOT NULL
               AND sa.minus_area IS NULL
                  THEN 'one_sided_plus_area'

              WHEN sa.plus_area IS NULL
               AND sa.minus_area IS NOT NULL
                  THEN 'one_sided_minus_area'

              ELSE 'invalid'
          END AS dap_kind
      FROM side_areas sa
  ),

  valid_dap AS (
      SELECT
          n.door_id,
          n.floor,
          n.dap_geom,
          n.door_mid,
          n.door_to_dap_m,
          n.plus_area,
          n.minus_area,
          n.plus_role,
          n.minus_role,
          n.plus_dist_m,
          n.minus_dist_m,
          n.edge_area_id,
          n.edge_role,
          n.final_from_area,
          n.final_to_area,
          n.dap_kind
      FROM normalized n
      WHERE n.door_to_dap_m <= v_search_m
        AND n.final_from_area IS NOT NULL
        AND n.dap_kind <> 'invalid'
  ),

  inserted AS (
      INSERT INTO public.door_access_points (
          door_id,
          geom,
          floor,
          from_area,
          to_area,
          confidence,
          build_method,
          needs_review,
          review_reason,
          snapped_from_geom,
          candidates
      )
      SELECT
          vd.door_id,
          vd.dap_geom,
          vd.floor,
          vd.final_from_area AS from_area,
          vd.final_to_area   AS to_area,

          GREATEST(
              0.0,
              LEAST(1.0, 1.0 - (vd.door_to_dap_m / v_search_m))
          ) AS confidence,

          CASE
              WHEN vd.dap_kind = 'two_sided_door'
                  THEN 'normal_nearest_non_transparent_area_edge_max_2m'
              ELSE 'normal_nearest_non_transparent_area_edge_max_2m_one_sided'
          END AS build_method,

          false AS needs_review,

          CASE
              WHEN vd.dap_kind = 'two_sided_door'
                  THEN NULL::text
              ELSE vd.dap_kind
          END AS review_reason,

          vd.door_mid AS snapped_from_geom,

          jsonb_build_object(
              'rule', 'nearest non-transparent/non-stair-ramp-elevator area boundary on perpendicular from door midpoint',
              'max_distance_m', v_search_m,
              'door_to_dap_m', vd.door_to_dap_m,
              'edge_area_id', vd.edge_area_id,
              'edge_role', vd.edge_role,
              'dap_kind', vd.dap_kind,
              'minus_area', vd.minus_area,
              'plus_area', vd.plus_area,
              'minus_role', vd.minus_role,
              'plus_role', vd.plus_role,
              'minus_probe_m', vd.minus_dist_m,
              'plus_probe_m', vd.plus_dist_m,
              'ignored_roles', jsonb_build_array('transparent'),
              'hard_ignored_area_types', jsonb_build_array(
                  'stair_area',
                  'ramp_area',
                  'elevator_area'
              )
          ) AS candidates
      FROM valid_dap vd
      RETURNING 1
  )

  SELECT COUNT(*) INTO v_inserted
  FROM inserted;

  RETURN v_inserted;
END;
$$;


--
-- Name: fn_rebuild_door_access_points_v3(smallint, double precision, double precision, boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_rebuild_door_access_points_v3(p_floor smallint DEFAULT NULL::smallint, p_snap_tol double precision DEFAULT 0.75, p_search_tol double precision DEFAULT 1.50, p_update_doors_from_to boolean DEFAULT false) RETURNS TABLE(total_doors integer, rebuilt integer, needs_review integer, no_candidate integer, one_sided integer, ambiguous integer)
    LANGUAGE plpgsql
    AS $$
BEGIN
  /*
    قانون اصلی:
    doors.geom فقط هندسه خام ترسیمی درب است.
    خروجی قابل استفاده برای routing فقط door_access_points است.
  */

  --------------------------------------------------------------------
  -- 1) پاکسازی DAPهای قبلی همان طبقه
  --------------------------------------------------------------------
  DELETE FROM public.door_access_points dap
  USING public.doors d
  WHERE dap.door_id = d.id
    AND (p_floor IS NULL OR d.floor = p_floor);

  --------------------------------------------------------------------
  -- 2) ساخت مجدد DAPها
  --------------------------------------------------------------------
  WITH door_line AS (
    SELECT
      d.id AS door_id,
      d.floor,
      d.geom AS original_geom,
      dl.geom::geometry(LineString, 32640) AS door_line,
      ST_LineInterpolatePoint(dl.geom, 0.5)::geometry(Point, 32640) AS raw_mid
    FROM public.doors d
    CROSS JOIN LATERAL (
      SELECT
        (dumped).geom AS geom
      FROM ST_Dump(
        ST_CollectionExtract(
          ST_MakeValid(d.geom),
          2
        )
      ) AS dumped
      WHERE NOT ST_IsEmpty((dumped).geom)
      ORDER BY ST_Length((dumped).geom) DESC
      LIMIT 1
    ) dl
    WHERE d.geom IS NOT NULL
      AND NOT ST_IsEmpty(d.geom)
      AND (p_floor IS NULL OR d.floor = p_floor)
  ),

  area_candidates AS (
    SELECT
      dl.door_id,
      dl.floor,
      dl.door_line,
      dl.raw_mid,

      a.id AS area_id,
      a.geom AS area_geom,
      a.area_type,
      COALESCE(a.attrs, '{}'::jsonb) AS attrs,
      public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb)) AS routing_role,

      ST_Distance(ST_Boundary(a.geom), dl.raw_mid) AS dist_to_boundary,

      ST_Length(
        ST_Intersection(
          ST_Buffer(dl.door_line, p_snap_tol),
          ST_Boundary(a.geom)
        )
      ) AS boundary_overlap,

      CASE public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb))
        WHEN 'routable' THEN 30.0
        WHEN 'doorstep_only' THEN 10.0
        WHEN 'transparent' THEN 5.0
        ELSE 0.0
      END AS role_priority

    FROM door_line dl
    JOIN public.areas a
      ON a.floor = dl.floor
     AND ST_DWithin(a.geom, dl.door_line, p_search_tol)
  ),

  scored AS (
    SELECT
      ac.*,

      (
        COALESCE(ac.boundary_overlap, 0.0) * 100.0
        - COALESCE(ac.dist_to_boundary, 9999.0) * 10.0
        + COALESCE(ac.role_priority, 0.0)
      ) AS score

    FROM area_candidates ac
  ),

  ranked AS (
    SELECT
      s.*,
      row_number() OVER (
        PARTITION BY s.door_id
        ORDER BY
          s.score DESC,
          s.boundary_overlap DESC,
          s.dist_to_boundary ASC,
          s.area_id ASC
      ) AS rn,
      count(*) OVER (PARTITION BY s.door_id) AS candidate_count
    FROM scored s
  ),

  picked AS (
    SELECT
      dl.door_id,
      dl.floor,
      dl.door_line,
      dl.raw_mid,

      max(r.area_id)      FILTER (WHERE r.rn = 1) AS area1_id,
      max(r.area_geom)    FILTER (WHERE r.rn = 1) AS area1_geom,
      max(r.routing_role) FILTER (WHERE r.rn = 1) AS area1_role,
      max(r.score)        FILTER (WHERE r.rn = 1) AS score1,

      max(r.area_id)      FILTER (WHERE r.rn = 2) AS area2_id,
      max(r.area_geom)    FILTER (WHERE r.rn = 2) AS area2_geom,
      max(r.routing_role) FILTER (WHERE r.rn = 2) AS area2_role,
      max(r.score)        FILTER (WHERE r.rn = 2) AS score2,

      max(r.score)        FILTER (WHERE r.rn = 3) AS score3,

      COALESCE(max(r.candidate_count), 0) AS candidate_count,

      COALESCE(
        jsonb_agg(
          jsonb_build_object(
            'areaId', r.area_id,
            'areaType', r.area_type::text,
            'routingRole', r.routing_role,
            'score', r.score,
            'boundaryOverlap', r.boundary_overlap,
            'distToBoundary', r.dist_to_boundary,
            'rank', r.rn
          )
          ORDER BY r.rn
        ) FILTER (WHERE r.area_id IS NOT NULL),
        '[]'::jsonb
      ) AS candidates_json

    FROM door_line dl
    LEFT JOIN ranked r
      ON r.door_id = dl.door_id
     AND r.rn <= 5
    GROUP BY
      dl.door_id,
      dl.floor,
      dl.door_line,
      dl.raw_mid
  ),

  normalized AS (
    SELECT
      p.*,

      CASE
        WHEN p.area1_id IS NULL THEN NULL::bigint

        WHEN p.area2_id IS NULL THEN p.area1_id

        WHEN p.area1_role = 'routable' AND p.area2_role <> 'routable'
          THEN p.area1_id

        WHEN p.area2_role = 'routable' AND p.area1_role <> 'routable'
          THEN p.area2_id

        WHEN p.area1_id < p.area2_id
          THEN p.area1_id

        ELSE p.area2_id
      END AS final_from_area,

      CASE
        WHEN p.area1_id IS NULL THEN NULL::bigint

        WHEN p.area2_id IS NULL THEN NULL::bigint

        WHEN p.area1_role = 'routable' AND p.area2_role <> 'routable'
          THEN p.area2_id

        WHEN p.area2_role = 'routable' AND p.area1_role <> 'routable'
          THEN p.area1_id

        WHEN p.area1_id < p.area2_id
          THEN p.area2_id

        ELSE p.area1_id
      END AS final_to_area,

      CASE
        WHEN p.area1_id IS NULL THEN true
        WHEN p.area2_id IS NULL THEN true
        WHEN p.candidate_count >= 3
             AND p.score3 IS NOT NULL
             AND p.score2 IS NOT NULL
             AND p.score3 >= (p.score2 * 0.97)
          THEN true
        ELSE false
      END AS final_needs_review,

      CASE
        WHEN p.area1_id IS NULL THEN 'no_area_candidate'
        WHEN p.area2_id IS NULL THEN 'one_sided_door'
        WHEN p.candidate_count >= 3
             AND p.score3 IS NOT NULL
             AND p.score2 IS NOT NULL
             AND p.score3 >= (p.score2 * 0.97)
          THEN 'ambiguous_multi_area'
        ELSE NULL::text
      END AS final_review_reason

    FROM picked p
  ),

  access_geom AS (
    SELECT
      n.*,

      CASE
        WHEN n.area1_geom IS NOT NULL
         AND n.area2_geom IS NOT NULL
         AND NOT ST_IsEmpty(
           ST_Intersection(
             ST_Boundary(n.area1_geom),
             ST_Boundary(n.area2_geom)
           )
         )
        THEN
          ST_ClosestPoint(
            ST_Intersection(
              ST_Boundary(n.area1_geom),
              ST_Boundary(n.area2_geom)
            ),
            n.raw_mid
          )::geometry(Point, 32640)

        WHEN n.area1_geom IS NOT NULL
        THEN
          ST_ClosestPoint(
            ST_Boundary(n.area1_geom),
            n.raw_mid
          )::geometry(Point, 32640)

        ELSE
          n.raw_mid::geometry(Point, 32640)
      END AS final_geom,

      CASE
        WHEN n.area1_id IS NULL THEN 0.0
        WHEN n.area2_id IS NULL THEN 0.35
        WHEN n.candidate_count >= 3
             AND n.score3 IS NOT NULL
             AND n.score2 IS NOT NULL
             AND n.score3 >= (n.score2 * 0.85)
          THEN 0.65
        ELSE 0.95
      END AS final_confidence

    FROM normalized n
  )

  INSERT INTO public.door_access_points (
    door_id,
    geom,
    floor,
    from_area,
    to_area,
    confidence,
    build_method,
    needs_review,
    review_reason,
    snapped_from_geom,
    candidates
  )
  SELECT
    ag.door_id,
    ag.final_geom,
    ag.floor,
    ag.final_from_area,
    ag.final_to_area,
    ag.final_confidence,
    'v3_line_mid_boundary_scoring',
    ag.final_needs_review,
    ag.final_review_reason,
    ag.raw_mid,
    ag.candidates_json
  FROM access_geom ag
  WHERE ag.final_geom IS NOT NULL
    AND NOT ST_IsEmpty(ag.final_geom);

  --------------------------------------------------------------------
  -- 3) آپدیت اختیاری doors.from_area / doors.to_area فقط برای سازگاری موقت
  --------------------------------------------------------------------
  IF p_update_doors_from_to THEN
    UPDATE public.doors d
    SET
      from_area = dap.from_area,
      to_area   = dap.to_area
    FROM public.door_access_points dap
    WHERE dap.door_id = d.id
      AND (p_floor IS NULL OR d.floor = p_floor);
  END IF;

    --------------------------------------------------------------------
  -- 4) خروجی خلاصه
  --------------------------------------------------------------------
  RETURN QUERY
  WITH target_doors AS (
    SELECT d.id
    FROM public.doors d
    WHERE d.geom IS NOT NULL
      AND NOT ST_IsEmpty(d.geom)
      AND (p_floor IS NULL OR d.floor = p_floor)
  ),
  built AS (
    SELECT
      dap.id,
      dap.needs_review AS dap_needs_review,
      dap.review_reason AS dap_review_reason
    FROM public.door_access_points dap
    JOIN public.doors d ON d.id = dap.door_id
    WHERE p_floor IS NULL OR d.floor = p_floor
  )
  SELECT
    (SELECT count(*)::integer FROM target_doors) AS total_doors,
    (SELECT count(*)::integer FROM built) AS rebuilt,
    (SELECT count(*)::integer FROM built b WHERE b.dap_needs_review = true) AS needs_review,
    (SELECT count(*)::integer FROM built b WHERE b.dap_review_reason = 'no_area_candidate') AS no_candidate,
    (SELECT count(*)::integer FROM built b WHERE b.dap_review_reason = 'one_sided_door') AS one_sided,
    (SELECT count(*)::integer FROM built b WHERE b.dap_review_reason = 'ambiguous_multi_area') AS ambiguous;

END;
$$;


--
-- Name: fn_rebuild_door_access_points_v4(smallint, double precision, double precision, boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_rebuild_door_access_points_v4(p_floor smallint DEFAULT NULL::smallint, p_snap_tol double precision DEFAULT 0.75, p_search_tol double precision DEFAULT 1.50, p_update_doors_from_to boolean DEFAULT false) RETURNS TABLE(total_doors integer, rebuilt integer, needs_review integer, no_candidate integer, one_sided integer, ambiguous integer, resolved_by_side_samples integer)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_total_doors integer := 0;
    v_rebuilt integer := 0;
    v_needs_review integer := 0;
    v_no_candidate integer := 0;
    v_one_sided integer := 0;
    v_ambiguous integer := 0;
    v_resolved_by_side_samples integer := 0;
BEGIN
    --------------------------------------------------------------------
    -- 1) اجرای rebuild پایه با منطق scoring و ساخت DAP
    --------------------------------------------------------------------
    SELECT
        r.total_doors,
        r.rebuilt,
        r.needs_review,
        r.no_candidate,
        r.one_sided,
        r.ambiguous
    INTO
        v_total_doors,
        v_rebuilt,
        v_needs_review,
        v_no_candidate,
        v_one_sided,
        v_ambiguous
    FROM public.fn_rebuild_door_access_points_v3(
        p_floor,
        p_snap_tol,
        p_search_tol,
        false
    ) r;

    --------------------------------------------------------------------
    -- 2) حل ambiguousها با side sample چند offsetی
    --------------------------------------------------------------------
    WITH resolved AS (
        SELECT
            dap.id AS dap_id,
            r.from_area,
            r.to_area,
            r.left_area,
            r.right_area,
            r.left_role,
            r.right_role,
            r.used_offset,
            r.confidence,
            r.status
        FROM public.door_access_points dap
        JOIN public.doors d
          ON d.id = dap.door_id
        CROSS JOIN LATERAL public.fn_resolve_door_areas_by_side_samples(
            d.geom,
            d.floor,
            ARRAY[
              0.40::double precision,
              0.60::double precision,
              0.80::double precision,
              1.00::double precision,
              1.20::double precision
            ]
        ) r
        WHERE dap.review_reason = 'ambiguous_multi_area'
          AND r.status = 'resolved_by_side_samples'
          AND (p_floor IS NULL OR d.floor = p_floor)
    ),

    updated AS (
        UPDATE public.door_access_points dap
        SET
            from_area = r.from_area,
            to_area = r.to_area,
            confidence = r.confidence,
            build_method = 'v4_line_mid_boundary_scoring_plus_side_samples',
            needs_review = false,
            review_reason = NULL,
            candidates = jsonb_set(
                COALESCE(dap.candidates, '[]'::jsonb),
                '{0}',
                jsonb_build_object(
                    'sideSampleResolved', true,
                    'leftArea', r.left_area,
                    'rightArea', r.right_area,
                    'leftRole', r.left_role,
                    'rightRole', r.right_role,
                    'usedOffset', r.used_offset,
                    'confidence', r.confidence
                ),
                true
            )
        FROM resolved r
        WHERE dap.id = r.dap_id
        RETURNING dap.id
    )

    SELECT count(*)::integer
    INTO v_resolved_by_side_samples
    FROM updated;

    --------------------------------------------------------------------
    -- 3) sync اختیاری با doors فقط برای سازگاری موقت UI/report
    --    routing نباید از doors.from_area/to_area استفاده کند.
    --------------------------------------------------------------------
    IF p_update_doors_from_to THEN
        UPDATE public.doors d
        SET
            from_area = dap.from_area,
            to_area   = dap.to_area
        FROM public.door_access_points dap
        WHERE dap.door_id = d.id
          AND (p_floor IS NULL OR d.floor = p_floor);
    END IF;

    --------------------------------------------------------------------
    -- 4) خروجی نهایی بعد از side sample patch
    --------------------------------------------------------------------
    RETURN QUERY
WITH built AS (
    SELECT
        dap.id,
        dap.review_reason AS dap_review_reason,
        dap.needs_review AS dap_needs_review
    FROM public.door_access_points dap
    JOIN public.doors d
      ON d.id = dap.door_id
    WHERE p_floor IS NULL OR d.floor = p_floor
)
SELECT
    v_total_doors::integer AS total_doors,
    count(b.id)::integer AS rebuilt,
    count(*) FILTER (WHERE b.dap_needs_review = true)::integer AS needs_review,
    count(*) FILTER (WHERE b.dap_review_reason = 'no_area_candidate')::integer AS no_candidate,
    count(*) FILTER (WHERE b.dap_review_reason = 'one_sided_door')::integer AS one_sided,
    count(*) FILTER (WHERE b.dap_review_reason = 'ambiguous_multi_area')::integer AS ambiguous,
    v_resolved_by_side_samples::integer AS resolved_by_side_samples
FROM built b;
END;
$$;


--
-- Name: fn_rebuild_graph_for_area(bigint, smallint, boolean, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_rebuild_graph_for_area(p_area_id bigint, p_floor smallint DEFAULT NULL::smallint, p_rebuild_mesh boolean DEFAULT false, p_mesh_step_m double precision DEFAULT 2.70, p_clearance_m double precision DEFAULT 0.20) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_floor smallint;
  v_deleted integer := 0;
  v_inserted integer := 0;
  v_unsafe integer := 0;
  v_mesh jsonb := NULL;
BEGIN
  SELECT COALESCE(p_floor, a.floor)
  INTO v_floor
  FROM public.areas a
  WHERE a.id = p_area_id
  LIMIT 1;

  IF v_floor IS NULL THEN
    RETURN jsonb_build_object(
      'ok', false,
      'msg', 'area_not_found',
      'area_id', p_area_id
    );
  END IF;

  IF p_rebuild_mesh THEN
    v_mesh := public.fn_rebuild_area_mesh_grid(
      p_area_id,
      v_floor,
      p_mesh_step_m,
      0.30
    );
  END IF;

  --------------------------------------------------------------------
  -- حذف intra-edgeهای همین محدوده
  --------------------------------------------------------------------
  WITH affected_nodes AS (
    SELECT id
    FROM public.routing_nodes
    WHERE floor = v_floor
      AND area_id = p_area_id
  )
  DELETE FROM public.routing_edges_static e
  USING affected_nodes an
  WHERE e.floor = v_floor
    AND e.door_id IS NULL
    AND (e.src = an.id OR e.dst = an.id);

  GET DIAGNOSTICS v_deleted = ROW_COUNT;

  --------------------------------------------------------------------
  -- ساخت intra-edgeهای همین محدوده با منطق امن
  -- فقط edgeهای معتبر insert می‌شوند.
  --------------------------------------------------------------------
  WITH valid_nodes AS (
  SELECT rn.*
  FROM public.routing_nodes rn
  JOIN public.door_access_points dap
    ON dap.id = rn.ref_id
   AND dap.floor = rn.floor
  WHERE rn.floor = v_floor
    AND rn.area_id = p_area_id
    AND rn.ref_table = 'door_access_points'
    AND COALESCE(dap.needs_review, false) = false
    AND (
         rn.area_id = dap.from_area
      OR rn.area_id = dap.to_area
    )
),
pairs AS (
  SELECT
    n1.id AS src,
    n2.id AS dst,
    n1.area_id,
    public.fn_build_intra_area_edge_geom(
      n1.area_id,
      v_floor,
      n1.geom,
      n2.geom,
      p_clearance_m
    ) AS edge_geom
  FROM valid_nodes n1
  JOIN valid_nodes n2
    ON n1.floor = n2.floor
   AND n1.area_id = n2.area_id
   AND n1.id < n2.id
),
  checked AS (
    SELECT
      p.*,
      public.fn_route_line_valid_inside_area(
        p.area_id,
        p.edge_geom,
        p_clearance_m
      ) AS is_valid
    FROM pairs p
    WHERE p.edge_geom IS NOT NULL
      AND NOT ST_IsEmpty(p.edge_geom)
      AND GeometryType(p.edge_geom) IN ('LINESTRING', 'ST_LineString')
  ),
  inserted AS (
    INSERT INTO public.routing_edges_static (
      floor,
      src,
      dst,
      geom,
      base_cost,
      door_id,
      attrs
    )
    SELECT
      v_floor,
      c.src,
      c.dst,
      c.edge_geom::geometry(LineString, 32640),
      GREATEST(ST_Length(c.edge_geom), 0.50)::numeric,
      NULL::bigint,
      jsonb_build_object(
        'edge_type', 'intra_area',
        'area_id', c.area_id,
        'safety', 'safe_or_mesh',
        'valid_inside_area', true,
        'clearance_m', p_clearance_m,
        'source', 'quality_patch_area_rebuild',
        'created_at', now()
      )
    FROM checked c
    WHERE c.is_valid = true
    RETURNING 1
  ),
  stats AS (
    SELECT
      (SELECT COUNT(*) FROM inserted) AS inserted_count,
      (SELECT COUNT(*) FROM checked WHERE is_valid = false) AS unsafe_count
  )
  SELECT
    inserted_count,
    unsafe_count
  INTO
    v_inserted,
    v_unsafe
  FROM stats;
	
	  --------------------------------------------------------------------
  -- بعد از ساخت edgeهای عادی، nodeهای DAP جداافتاده را وصل کن.
  --------------------------------------------------------------------
  PERFORM public.fn_repair_isolated_dap_nodes_for_area(
      p_area_id,
      v_floor,
      p_clearance_m
  );

  RETURN jsonb_build_object(
    'ok', true,
    'area_id', p_area_id,
    'floor', v_floor,
    'deleted_edges', v_deleted,
    'inserted_edges', COALESCE(v_inserted, 0),
    'unsafe_rejected_edges', COALESCE(v_unsafe, 0),
    'mesh', v_mesh
  );
END;
$$;


--
-- Name: fn_rebuild_graph_for_door(bigint, boolean, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_rebuild_graph_for_door(p_door_id bigint, p_rebuild_mesh boolean DEFAULT false, p_mesh_step_m double precision DEFAULT 2.50, p_clearance_m double precision DEFAULT 0.15) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_floor smallint;

    v_deleted_related_edges integer := 0;
    v_deleted_door_edges integer := 0;
    v_deleted_nodes integer := 0;
    v_inserted_nodes integer := 0;
    v_inserted_forward_edges integer := 0;
    v_inserted_reverse_edges integer := 0;

    v_area_id bigint;
    v_area_res jsonb;
    v_area_results jsonb := '[]'::jsonb;
    v_area_ok integer := 0;
    v_area_failed integer := 0;
BEGIN
    --------------------------------------------------------------------
    -- 0) تشخیص floor از doors.
    -- doors اینجا فقط metadata است، نه منبع topology/geometry routing.
    --------------------------------------------------------------------
    SELECT d.floor
    INTO v_floor
    FROM public.doors d
    WHERE d.id = p_door_id;

    IF v_floor IS NULL THEN
        RAISE EXCEPTION 'Door % not found', p_door_id;
    END IF;

    --------------------------------------------------------------------
    -- 1) ثبت areaهای متاثر، هم از گراف فعلی، هم از DAP فعلی.
    --
    -- دلیل:
    -- اگر درب جابه‌جا شده باشد، node قدیمی ممکن است area قبلی را داشته باشد
    -- و DAP جدید area جدید را. هر دو باید rebuild شوند.
    --------------------------------------------------------------------
    CREATE TEMP TABLE tmp_door_affected_areas (
        area_id bigint PRIMARY KEY
    ) ON COMMIT DROP;

    INSERT INTO tmp_door_affected_areas(area_id)
    SELECT DISTINCT x.area_id
    FROM (
        ----------------------------------------------------------------
        -- areaهای فعلی/قدیمی از روی routing_nodes موجود
        ----------------------------------------------------------------
        SELECT rn.area_id
        FROM public.routing_nodes rn
        JOIN public.door_access_points dap
          ON dap.id = rn.ref_id
        WHERE rn.floor = v_floor
          AND rn.ref_table = 'door_access_points'
          AND dap.door_id = p_door_id
          AND rn.area_id IS NOT NULL

        UNION ALL

        ----------------------------------------------------------------
        -- areaهای جدید از روی DAP فعلی
        ----------------------------------------------------------------
        SELECT dap.from_area
        FROM public.door_access_points dap
        WHERE dap.floor = v_floor
          AND dap.door_id = p_door_id
          AND dap.from_area IS NOT NULL

        UNION ALL

        SELECT dap.to_area
        FROM public.door_access_points dap
        WHERE dap.floor = v_floor
          AND dap.door_id = p_door_id
          AND dap.to_area IS NOT NULL
    ) x
    WHERE x.area_id IS NOT NULL
    ON CONFLICT (area_id) DO NOTHING;

    --------------------------------------------------------------------
    -- 2) حذف edgeهای متصل به nodeهای فعلی همین door_access_points.
    --
    -- routing_edges_static به routing_nodes FK دارد، پس اول edge حذف می‌شود.
    --------------------------------------------------------------------
    WITH door_access_ids AS (
        SELECT dap.id AS access_id
        FROM public.door_access_points dap
        WHERE dap.floor = v_floor
          AND dap.door_id = p_door_id
    ),
    door_nodes AS (
        SELECT rn.id AS node_id
        FROM public.routing_nodes rn
        JOIN door_access_ids dai
          ON dai.access_id = rn.ref_id
        WHERE rn.floor = v_floor
          AND rn.ref_table = 'door_access_points'
    ),
    deleted AS (
        DELETE FROM public.routing_edges_static e
        USING door_nodes dn
        WHERE e.floor = v_floor
          AND (
              e.src = dn.node_id
              OR e.dst = dn.node_id
          )
        RETURNING e.id
    )
    SELECT count(*)::integer
    INTO v_deleted_related_edges
    FROM deleted;

    --------------------------------------------------------------------
    -- 3) حذف همه edgeهای door_transition همین درب.
    -- این برای حالتی است که edge.door_id ست شده، حتی اگر nodeها عوض شده باشند.
    --------------------------------------------------------------------
    DELETE FROM public.routing_edges_static e
    WHERE e.floor = v_floor
      AND e.door_id = p_door_id;

    GET DIAGNOSTICS v_deleted_door_edges = ROW_COUNT;

    --------------------------------------------------------------------
    -- 4) حذف nodeهای همین door_access_points.
    --------------------------------------------------------------------
    WITH door_access_ids AS (
        SELECT dap.id AS access_id
        FROM public.door_access_points dap
        WHERE dap.floor = v_floor
          AND dap.door_id = p_door_id
    ),
    deleted AS (
        DELETE FROM public.routing_nodes rn
        USING door_access_ids dai
        WHERE rn.floor = v_floor
          AND rn.ref_table = 'door_access_points'
          AND rn.ref_id = dai.access_id
        RETURNING rn.id
    )
    SELECT count(*)::integer
    INTO v_deleted_nodes
    FROM deleted;

    --------------------------------------------------------------------
    -- 5) ساخت nodeهای جدید از door_access_points.
    --
    -- نکته مهم:
    -- ref_table/ref_id به door_access_points اشاره می‌کند.
    -- geom هم فقط از dap.geom می‌آید.
    --------------------------------------------------------------------
    INSERT INTO public.routing_nodes (
        floor,
        geom,
        kind,
        ref_table,
        ref_id,
        area_id
    )
    SELECT
        dap.floor,
        public.fn_dap_node_geom_inside_area(
        dap.geom,
        dap.from_area,
        p_clearance_m
    ),
        'door_access'::text,
        'door_access_points'::text,
        dap.id,
        dap.from_area
    FROM public.door_access_points dap
    WHERE dap.floor = v_floor
      AND dap.door_id = p_door_id
      AND dap.from_area IS NOT NULL
      AND COALESCE(dap.needs_review, false) = false

    UNION ALL

    SELECT
        dap.floor,
        public.fn_dap_node_geom_inside_area(
        dap.geom,
        dap.to_area,
        p_clearance_m
    ),
        'door_access'::text,
        'door_access_points'::text,
        dap.id,
        dap.to_area
    FROM public.door_access_points dap
    WHERE dap.floor = v_floor
      AND dap.door_id = p_door_id
      AND dap.to_area IS NOT NULL
      AND COALESCE(dap.needs_review, false) = false;

    GET DIAGNOSTICS v_inserted_nodes = ROW_COUNT;

    --------------------------------------------------------------------
    -- 6) اگر DAP جدید area تازه‌ای دارد، مطمئن شو در لیست affected هست.
    --------------------------------------------------------------------
    INSERT INTO tmp_door_affected_areas(area_id)
    SELECT DISTINCT x.area_id
    FROM (
        SELECT dap.from_area AS area_id
        FROM public.door_access_points dap
        WHERE dap.floor = v_floor
          AND dap.door_id = p_door_id
          AND dap.from_area IS NOT NULL
          AND COALESCE(dap.needs_review, false) = false

        UNION ALL

        SELECT dap.to_area AS area_id
        FROM public.door_access_points dap
        WHERE dap.floor = v_floor
          AND dap.door_id = p_door_id
          AND dap.to_area IS NOT NULL
          AND COALESCE(dap.needs_review, false) = false
    ) x
    WHERE x.area_id IS NOT NULL
    ON CONFLICT (area_id) DO NOTHING;

    --------------------------------------------------------------------
    -- 7) بازسازی intra-area edgeها فقط برای محدوده‌های متاثر.
    --
    -- اینجا دیگر ST_MakeLine مستقیم بین همه nodeهای area نمی‌زنیم.
    -- از fn_rebuild_graph_for_area استفاده می‌کنیم که مسیر داخل محدوده را
    -- با منطق امن‌تر و fn_build_intra_area_edge_geom می‌سازد.
    --------------------------------------------------------------------
    FOR v_area_id IN
        SELECT taa.area_id
        FROM tmp_door_affected_areas taa
        JOIN public.areas a
          ON a.id = taa.area_id
         AND a.floor = v_floor
        WHERE public.fn_area_routing_role(
                  a.area_type,
                  COALESCE(a.attrs, '{}'::jsonb)
              ) = 'routable'
        ORDER BY taa.area_id
    LOOP
        BEGIN
            SELECT public.fn_rebuild_graph_for_area(
                v_area_id::bigint,
                v_floor::smallint,
                p_rebuild_mesh::boolean,
                p_clearance_m::double precision
            )
            INTO v_area_res;

            v_area_ok := v_area_ok + 1;

            v_area_results := v_area_results || jsonb_build_array(
                jsonb_build_object(
                    'area_id', v_area_id,
                    'ok', true,
                    'result', COALESCE(v_area_res, '{}'::jsonb)
                )
            );

        EXCEPTION WHEN OTHERS THEN
            v_area_failed := v_area_failed + 1;

            v_area_results := v_area_results || jsonb_build_array(
                jsonb_build_object(
                    'area_id', v_area_id,
                    'ok', false,
                    'error', SQLERRM
                )
            );

            RAISE NOTICE 'fn_rebuild_graph_for_area failed for door %, area %, error: %',
                p_door_id,
                v_area_id,
                SQLERRM;
        END;
    END LOOP;

    --------------------------------------------------------------------
    -- 8) ساخت edge عبور از درب؛ forward.
    --
    -- هندسه از routing_nodes می‌آید.
    -- door_id فقط برای status/rule/name نگه داشته می‌شود.
    --------------------------------------------------------------------
    INSERT INTO public.routing_edges_static (
        floor,
        src,
        dst,
        geom,
        base_cost,
        door_id,
        attrs
    )
    SELECT
        dap.floor,
        n_from.id,
        n_to.id,
        ST_MakeLine(n_from.geom, n_to.geom)::geometry(LineString, 32640),
        GREATEST(ST_Distance(n_from.geom, n_to.geom), 0.50)::numeric,
        dap.door_id,
        jsonb_build_object(
            'edge_type', 'door_transition',
            'source', 'door_access_points',
            'builder', 'fn_rebuild_graph_for_door',
            'direction', 'forward',
            'access_id', dap.id,
            'door_id', dap.door_id,
            'from_area', dap.from_area,
            'to_area', dap.to_area,
            'bidirectional', COALESCE(d.bidirectional, true),
            'created_at', now()
        )
    FROM public.door_access_points dap
    JOIN public.doors d
      ON d.id = dap.door_id
    JOIN public.routing_nodes n_from
      ON n_from.floor = dap.floor
     AND n_from.ref_table = 'door_access_points'
     AND n_from.ref_id = dap.id
     AND n_from.area_id = dap.from_area
    JOIN public.routing_nodes n_to
      ON n_to.floor = dap.floor
     AND n_to.ref_table = 'door_access_points'
     AND n_to.ref_id = dap.id
     AND n_to.area_id = dap.to_area
    WHERE dap.floor = v_floor
      AND dap.door_id = p_door_id
      AND COALESCE(dap.needs_review, false) = false
      AND dap.from_area IS NOT NULL
      AND dap.to_area IS NOT NULL;

    GET DIAGNOSTICS v_inserted_forward_edges = ROW_COUNT;

    --------------------------------------------------------------------
    -- 9) ساخت edge برگشتی فقط برای درب‌های دوطرفه.
    --------------------------------------------------------------------
    INSERT INTO public.routing_edges_static (
        floor,
        src,
        dst,
        geom,
        base_cost,
        door_id,
        attrs
    )
    SELECT
        dap.floor,
        n_to.id,
        n_from.id,
        ST_MakeLine(n_to.geom, n_from.geom)::geometry(LineString, 32640),
        GREATEST(ST_Distance(n_to.geom, n_from.geom), 0.50)::numeric,
        dap.door_id,
        jsonb_build_object(
            'edge_type', 'door_transition',
            'source', 'door_access_points',
            'builder', 'fn_rebuild_graph_for_door',
            'direction', 'reverse',
            'access_id', dap.id,
            'door_id', dap.door_id,
            'from_area', dap.to_area,
            'to_area', dap.from_area,
            'bidirectional', true,
            'created_at', now()
        )
    FROM public.door_access_points dap
    JOIN public.doors d
      ON d.id = dap.door_id
    JOIN public.routing_nodes n_from
      ON n_from.floor = dap.floor
     AND n_from.ref_table = 'door_access_points'
     AND n_from.ref_id = dap.id
     AND n_from.area_id = dap.from_area
    JOIN public.routing_nodes n_to
      ON n_to.floor = dap.floor
     AND n_to.ref_table = 'door_access_points'
     AND n_to.ref_id = dap.id
     AND n_to.area_id = dap.to_area
    WHERE dap.floor = v_floor
      AND dap.door_id = p_door_id
      AND COALESCE(dap.needs_review, false) = false
      AND dap.from_area IS NOT NULL
      AND dap.to_area IS NOT NULL
      AND COALESCE(d.bidirectional, true) = true;

    GET DIAGNOSTICS v_inserted_reverse_edges = ROW_COUNT;

    --------------------------------------------------------------------
    -- 10) خروجی کنترلی
    --------------------------------------------------------------------
    RETURN jsonb_build_object(
        'ok', true,
        'door_id', p_door_id,
        'floor', v_floor,
        'deleted_related_edges', v_deleted_related_edges,
        'deleted_door_edges', v_deleted_door_edges,
        'deleted_nodes', v_deleted_nodes,
        'inserted_nodes', v_inserted_nodes,
        'inserted_forward_door_edges', v_inserted_forward_edges,
        'inserted_reverse_door_edges', v_inserted_reverse_edges,
        'affected_areas_count', (
            SELECT count(*) FROM tmp_door_affected_areas
        ),
        'areas_rebuilt_ok', v_area_ok,
        'areas_rebuilt_failed', v_area_failed,
        'area_results', v_area_results,
        'routing_source', 'door_access_points'
    );
END;
$$;


--
-- Name: fn_rebuild_mesh_for_unsafe_areas(smallint, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_rebuild_mesh_for_unsafe_areas(p_floor smallint, p_step_m double precision DEFAULT 4.0) RETURNS TABLE(area_id bigint, floor smallint, result jsonb)
    LANGUAGE plpgsql
    AS $$
BEGIN
  RETURN QUERY
  WITH unsafe_areas AS (
    SELECT DISTINCT
      n1.area_id,
      e.floor
    FROM public.routing_edges_static e
    JOIN public.routing_nodes n1 ON n1.id = e.src
    JOIN public.routing_nodes n2 ON n2.id = e.dst
    WHERE e.floor = p_floor
      AND e.door_id IS NULL
      AND n1.area_id = n2.area_id
      AND n1.area_id IS NOT NULL
      AND e.attrs->>'safety' = 'unsafe_direct_fallback'
  )
  SELECT
    ua.area_id,
    ua.floor,
    public.fn_rebuild_area_mesh_grid(ua.area_id, ua.floor, p_step_m, 0.35) AS result
  FROM unsafe_areas ua;
END;
$$;


--
-- Name: fn_rebuild_routing_floor(smallint, boolean, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_rebuild_routing_floor(p_floor smallint, p_rebuild_dap boolean DEFAULT false, p_snap_tol double precision DEFAULT 0.75, p_search_tol double precision DEFAULT 1.50) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_dap_result record;
    v_nodes_before integer := 0;
    v_nodes_after integer := 0;
    v_edges_before integer := 0;
    v_edges_after integer := 0;
    v_door_edges integer := 0;
    v_intra_edges integer := 0;
    v_bad_nodes integer := 0;
    v_bad_door_edges integer := 0;
    v_edges_result jsonb := '{}'::jsonb;
BEGIN
    --------------------------------------------------------------------
    -- قانون معماری:
    -- doors.geom فقط هندسه خام نمایشی است.
    -- DAP تنها منبع routing است.
    --------------------------------------------------------------------

    SELECT count(*)::integer
    INTO v_nodes_before
    FROM public.routing_nodes rn
    WHERE rn.floor = p_floor;

    SELECT count(*)::integer
    INTO v_edges_before
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor;

    --------------------------------------------------------------------
    -- 1) بازسازی door_access_points
    --------------------------------------------------------------------
    IF p_rebuild_dap THEN
        RAISE NOTICE 'Rebuilding door_access_points for floor %...', p_floor;

        SELECT *
        INTO v_dap_result
--         FROM public.fn_rebuild_door_access_points_v4(
--             p_floor,
--             p_snap_tol,
--             p_search_tol,
--             false
--         );
				
				FROM public.fn_rebuild_door_access_points_v2(
            p_floor
        );

        RAISE NOTICE 'door_access_points rebuilt for floor %. total=%, rebuilt=%, needs_review=%',
            p_floor,
            v_dap_result.total_doors,
            v_dap_result.rebuilt,
            v_dap_result.needs_review;
    ELSE
        RAISE NOTICE 'Skipping door_access_points rebuild for floor %.', p_floor;
    END IF;

    --------------------------------------------------------------------
    -- 2) refresh آمار area-door از روی door_access_points
    -- این برای fn_allowed_areas مهم است.
    --------------------------------------------------------------------
    RAISE NOTICE 'Refreshing mv_area_door_stats...';
    REFRESH MATERIALIZED VIEW public.mv_area_door_stats;

    --------------------------------------------------------------------
    -- 3) ساخت nodeها فقط از door_access_points
    -- این تابع edgeهای همان floor را هم پاک می‌کند.
    --------------------------------------------------------------------
    RAISE NOTICE 'Building routing_nodes from door_access_points for floor %...', p_floor;

    PERFORM public.fn_build_routing_nodes(p_floor);

    --------------------------------------------------------------------
    -- 4) ساخت edgeها:
    -- intra-area با fn_rebuild_graph_for_area
    -- door-transition با door_access_points
    --------------------------------------------------------------------
    RAISE NOTICE 'Building routing_edges_static for floor %...', p_floor;

    SELECT public.fn_build_routing_edges(p_floor)
    INTO v_edges_result;

    --------------------------------------------------------------------
    -- 5) refresh نهایی stats
    --------------------------------------------------------------------
    RAISE NOTICE 'Refreshing mv_area_door_stats after graph build...';
    REFRESH MATERIALIZED VIEW public.mv_area_door_stats;

    --------------------------------------------------------------------
    -- 6) کنترل نهایی
    --------------------------------------------------------------------
    SELECT count(*)::integer
    INTO v_nodes_after
    FROM public.routing_nodes rn
    WHERE rn.floor = p_floor;

    SELECT count(*)::integer
    INTO v_edges_after
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor;

    SELECT count(*)::integer
    INTO v_door_edges
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor
      AND e.door_id IS NOT NULL;

    SELECT count(*)::integer
    INTO v_intra_edges
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor
      AND e.door_id IS NULL;

    SELECT count(*)::integer
    INTO v_bad_nodes
    FROM public.routing_nodes rn
    WHERE rn.floor = p_floor
      AND rn.ref_table = 'doors';

    SELECT count(*)::integer
    INTO v_bad_door_edges
    FROM public.routing_edges_static e
    WHERE e.floor = p_floor
      AND e.door_id IS NOT NULL
      AND NULLIF(e.attrs->>'access_id', '') IS NULL;

    RETURN jsonb_build_object(
        'ok', true,
        'floor', p_floor,

        'dap_rebuilt', p_rebuild_dap,
        'dap_result', CASE
            WHEN p_rebuild_dap THEN jsonb_build_object(
                'total_doors', v_dap_result.total_doors,
                'rebuilt', v_dap_result.rebuilt,
                'needs_review', v_dap_result.needs_review,
                'no_candidate', v_dap_result.no_candidate,
                'one_sided', v_dap_result.one_sided,
                'ambiguous', v_dap_result.ambiguous,
                'resolved_by_side_samples', v_dap_result.resolved_by_side_samples
            )
            ELSE NULL
        END,

        'nodes_before', v_nodes_before,
        'nodes_after', v_nodes_after,

        'edges_before', v_edges_before,
        'edges_after', v_edges_after,
        'intra_area_edges', v_intra_edges,
        'door_transition_edges', v_door_edges,

        'edges_result', v_edges_result,

        'bad_nodes_ref_doors', v_bad_nodes,
        'bad_door_edges_without_access_id', v_bad_door_edges,

        'routing_geometry_source', 'door_access_points',
        'node_source', 'door_access_points',
        'edge_source', 'routing_nodes'
    );
END;
$$;


--
-- Name: fn_remove_door_from_graph(bigint, boolean, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_remove_door_from_graph(p_door_id bigint, p_rebuild_mesh boolean DEFAULT false, p_mesh_step_m double precision DEFAULT 2.50, p_clearance_m double precision DEFAULT 0.20) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_floor smallint;
  v_area_id bigint;
  v_area_ids bigint[];
  v_access_ids bigint[];
  v_deleted_edges integer := 0;
  v_deleted_nodes integer := 0;
  v_deleted_dap integer := 0;
  v_area_ok integer := 0;
  v_area_failed integer := 0;
  v_area_res jsonb;
BEGIN
  --------------------------------------------------------------------
  -- 1) تشخیص floor از هر منبع ممکن
  --------------------------------------------------------------------
  SELECT d.floor
  INTO v_floor
  FROM public.doors d
  WHERE d.id = p_door_id;

  IF v_floor IS NULL THEN
    SELECT dap.floor
    INTO v_floor
    FROM public.door_access_points dap
    WHERE dap.door_id = p_door_id
    LIMIT 1;
  END IF;

  IF v_floor IS NULL THEN
    SELECT e.floor
    INTO v_floor
    FROM public.routing_edges_static e
    WHERE e.door_id = p_door_id
       OR NULLIF(e.attrs->>'door_id','')::bigint = p_door_id
    LIMIT 1;
  END IF;

  IF v_floor IS NULL THEN
    RETURN jsonb_build_object(
      'ok', false,
      'door_id', p_door_id,
      'msg', 'floor_not_found'
    );
  END IF;

  --------------------------------------------------------------------
  -- 2) گرفتن access_idهای این درب
  --------------------------------------------------------------------
  SELECT COALESCE(array_agg(dap.id), ARRAY[]::bigint[])
  INTO v_access_ids
  FROM public.door_access_points dap
  WHERE dap.door_id = p_door_id
    AND dap.floor = v_floor;

  --------------------------------------------------------------------
  -- 3) areaهای متاثر
  --------------------------------------------------------------------
  SELECT ARRAY(
    SELECT DISTINCT area_id
    FROM (
      SELECT d.from_area AS area_id
      FROM public.doors d
      WHERE d.id = p_door_id
        AND d.from_area IS NOT NULL

      UNION ALL

      SELECT d.to_area AS area_id
      FROM public.doors d
      WHERE d.id = p_door_id
        AND d.to_area IS NOT NULL

      UNION ALL

      SELECT dap.from_area AS area_id
      FROM public.door_access_points dap
      WHERE dap.door_id = p_door_id
        AND dap.from_area IS NOT NULL

      UNION ALL

      SELECT dap.to_area AS area_id
      FROM public.door_access_points dap
      WHERE dap.door_id = p_door_id
        AND dap.to_area IS NOT NULL

      UNION ALL

      SELECT rn.area_id
      FROM public.routing_nodes rn
      WHERE rn.floor = v_floor
        AND rn.ref_table = 'door_access_points'
        AND rn.ref_id = ANY(v_access_ids)
        AND rn.area_id IS NOT NULL

      UNION ALL

      -- برای پاکسازی بقایای معماری قدیمی
      SELECT rn.area_id
      FROM public.routing_nodes rn
      WHERE rn.floor = v_floor
        AND rn.ref_table = 'doors'
        AND rn.ref_id = p_door_id
        AND rn.area_id IS NOT NULL
    ) s
    WHERE area_id IS NOT NULL
  )
  INTO v_area_ids;

  --------------------------------------------------------------------
  -- 4) حذف edgeهای وابسته به nodeهای DAP و edgeهای خود door
  --------------------------------------------------------------------
  WITH door_nodes AS (
    SELECT rn.id
    FROM public.routing_nodes rn
    WHERE rn.floor = v_floor
      AND (
        (
          rn.ref_table = 'door_access_points'
          AND rn.ref_id = ANY(v_access_ids)
        )
        OR
        (
          rn.ref_table = 'doors'
          AND rn.ref_id = p_door_id
        )
      )
  ),
  deleted AS (
    DELETE FROM public.routing_edges_static e
    USING door_nodes dn
    WHERE e.floor = v_floor
      AND (
        e.src = dn.id
        OR e.dst = dn.id
        OR e.door_id = p_door_id
        OR NULLIF(e.attrs->>'door_id','')::bigint = p_door_id
        OR NULLIF(e.attrs->>'access_id','')::bigint = ANY(v_access_ids)
      )
    RETURNING e.id
  )
  SELECT count(*)::integer
  INTO v_deleted_edges
  FROM deleted;

  --------------------------------------------------------------------
  -- 5) حذف nodeهای DAP همین درب
  --------------------------------------------------------------------
  DELETE FROM public.routing_nodes rn
  WHERE rn.floor = v_floor
    AND (
      (
        rn.ref_table = 'door_access_points'
        AND rn.ref_id = ANY(v_access_ids)
      )
      OR
      (
        rn.ref_table = 'doors'
        AND rn.ref_id = p_door_id
      )
    );

  GET DIAGNOSTICS v_deleted_nodes = ROW_COUNT;

  --------------------------------------------------------------------
  -- 6) حذف خود door_access_points
  --------------------------------------------------------------------
  DELETE FROM public.door_access_points dap
  WHERE dap.door_id = p_door_id
    AND dap.floor = v_floor;

  GET DIAGNOSTICS v_deleted_dap = ROW_COUNT;

  --------------------------------------------------------------------
  -- 7) بازسازی edgeهای داخل areaهای متاثر
  --------------------------------------------------------------------
  IF v_area_ids IS NOT NULL AND array_length(v_area_ids, 1) IS NOT NULL THEN
    FOREACH v_area_id IN ARRAY v_area_ids
    LOOP
      BEGIN
        SELECT public.fn_rebuild_graph_for_area(
          v_area_id,
          v_floor,
          p_rebuild_mesh,
          p_mesh_step_m,
          p_clearance_m
        )
        INTO v_area_res;

        v_area_ok := v_area_ok + 1;
      EXCEPTION WHEN OTHERS THEN
        v_area_failed := v_area_failed + 1;
      END;
    END LOOP;
  END IF;

  --------------------------------------------------------------------
  -- 8) آمار area-door باید بعد از حذف DAP تازه شود
  --------------------------------------------------------------------
  REFRESH MATERIALIZED VIEW public.mv_area_door_stats;

  RETURN jsonb_build_object(
    'ok', true,
    'door_id', p_door_id,
    'floor', v_floor,
    'access_ids', COALESCE(v_access_ids, ARRAY[]::bigint[]),
    'affected_areas', COALESCE(v_area_ids, ARRAY[]::bigint[]),
    'deleted_edges', v_deleted_edges,
    'deleted_nodes', v_deleted_nodes,
    'deleted_dap', v_deleted_dap,
    'areas_rebuilt_ok', v_area_ok,
    'areas_rebuilt_failed', v_area_failed,
    'routing_source', 'door_access_points'
  );
END;
$$;


--
-- Name: fn_repair_isolated_dap_nodes_for_area(bigint, smallint, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_repair_isolated_dap_nodes_for_area(p_area_id bigint, p_floor smallint DEFAULT NULL::smallint, p_clearance_m double precision DEFAULT 0.20) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_floor smallint;
    v_isolated_count integer := 0;
    v_candidate_count integer := 0;
    v_valid_count integer := 0;
    v_inserted integer := 0;
BEGIN
    SELECT COALESCE(p_floor, a.floor)
    INTO v_floor
    FROM public.areas a
    WHERE a.id = p_area_id
    LIMIT 1;

    IF v_floor IS NULL THEN
        RETURN jsonb_build_object(
            'ok', false,
            'msg', 'area_not_found',
            'area_id', p_area_id
        );
    END IF;

    WITH valid_nodes AS (
    SELECT rn.*
    FROM public.routing_nodes rn
    JOIN public.door_access_points dap
      ON dap.id = rn.ref_id
     AND dap.floor = rn.floor
    WHERE rn.floor = v_floor
      AND rn.area_id = p_area_id
      AND rn.ref_table = 'door_access_points'
      AND COALESCE(dap.needs_review, false) = false
      AND (
           rn.area_id = dap.from_area
        OR rn.area_id = dap.to_area
      )
),
isolated AS (
    SELECT
        rn.id AS src,
        rn.geom AS src_geom,
        rn.area_id
    FROM valid_nodes rn
    WHERE NOT EXISTS (
        SELECT 1
        FROM public.routing_edges_static e
        WHERE e.floor = rn.floor
          AND e.door_id IS NULL
          AND (e.src = rn.id OR e.dst = rn.id)
    )
),
candidate_pool AS (
    SELECT
        i.src,
        n2.id AS dst,
        i.area_id,
        row_number() OVER (
            PARTITION BY i.src
            ORDER BY i.src_geom <-> n2.geom
        ) AS rn,
        public.fn_build_intra_area_edge_geom(
            i.area_id,
            v_floor,
            i.src_geom,
            n2.geom,
            p_clearance_m
        )::geometry(LineString, 32640) AS edge_geom
    FROM isolated i
    JOIN valid_nodes n2
      ON n2.floor = v_floor
     AND n2.area_id = i.area_id
     AND n2.id <> i.src
    WHERE EXISTS (
        SELECT 1
        FROM public.routing_edges_static e2
        WHERE e2.floor = v_floor
          AND e2.door_id IS NULL
          AND (e2.src = n2.id OR e2.dst = n2.id)
    )
),

    candidates AS (
        SELECT *
        FROM candidate_pool
        WHERE rn <= 20
          AND edge_geom IS NOT NULL
          AND NOT ST_IsEmpty(edge_geom)
    ),

    valid_candidates AS (
        SELECT
            c.*
        FROM candidates c
        WHERE public.fn_route_line_valid_inside_area_dap_connector(
                  c.area_id,
                  c.edge_geom,
                  p_clearance_m,
                  0.08
              ) = true
    ),

    chosen AS (
        SELECT DISTINCT ON (vc.src)
            vc.*
        FROM valid_candidates vc
        ORDER BY vc.src, vc.rn
    ),

    inserted_forward AS (
        INSERT INTO public.routing_edges_static (
            floor,
            src,
            dst,
            geom,
            base_cost,
            door_id,
            attrs
        )
        SELECT
            v_floor,
            c.src,
            c.dst,
            c.edge_geom,
            GREATEST(ST_Length(c.edge_geom), 0.50)::numeric,
            NULL::bigint,
            jsonb_build_object(
                'edge_type', 'intra_area',
                'area_id', c.area_id,
                'safety', 'safe_or_mesh',
                'valid_inside_area', true,
                'clearance_m', p_clearance_m,
                'source', 'repair_isolated_dap_node',
                'direction', 'forward',
                'created_at', now()
            )
        FROM chosen c
        RETURNING 1
    ),

    inserted_reverse AS (
        INSERT INTO public.routing_edges_static (
            floor,
            src,
            dst,
            geom,
            base_cost,
            door_id,
            attrs
        )
        SELECT
            v_floor,
            c.dst,
            c.src,
            ST_Reverse(c.edge_geom)::geometry(LineString, 32640),
            GREATEST(ST_Length(c.edge_geom), 0.50)::numeric,
            NULL::bigint,
            jsonb_build_object(
                'edge_type', 'intra_area',
                'area_id', c.area_id,
                'safety', 'safe_or_mesh',
                'valid_inside_area', true,
                'clearance_m', p_clearance_m,
                'source', 'repair_isolated_dap_node',
                'direction', 'reverse',
                'created_at', now()
            )
        FROM chosen c
        RETURNING 1
    )

    SELECT
        (SELECT count(*) FROM isolated),
        (SELECT count(*) FROM candidates),
        (SELECT count(*) FROM valid_candidates),
        (SELECT count(*) FROM inserted_forward) + (SELECT count(*) FROM inserted_reverse)
    INTO
        v_isolated_count,
        v_candidate_count,
        v_valid_count,
        v_inserted;

    RETURN jsonb_build_object(
        'ok', true,
        'area_id', p_area_id,
        'floor', v_floor,
        'isolated_nodes', v_isolated_count,
        'candidate_edges_checked', v_candidate_count,
        'valid_candidates', v_valid_count,
        'inserted_edges', v_inserted
    );
END;
$$;


--
-- Name: fn_resolve_door_areas_by_side_samples(public.geometry, smallint, double precision[]); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_resolve_door_areas_by_side_samples(p_door_geom public.geometry, p_floor smallint, p_offsets double precision[] DEFAULT ARRAY[(0.40)::double precision, (0.60)::double precision, (0.80)::double precision, (1.00)::double precision, (1.20)::double precision]) RETURNS TABLE(from_area bigint, to_area bigint, left_area bigint, right_area bigint, left_role text, right_role text, used_offset double precision, confidence double precision, status text)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_offset double precision;
BEGIN
    IF p_door_geom IS NULL OR ST_IsEmpty(p_door_geom) THEN
        RETURN QUERY
        SELECT
            NULL::bigint,
            NULL::bigint,
            NULL::bigint,
            NULL::bigint,
            NULL::text,
            NULL::text,
            NULL::double precision,
            0.0::double precision,
            'empty_door_geom'::text;
        RETURN;
    END IF;

    FOREACH v_offset IN ARRAY p_offsets LOOP

        RETURN QUERY
        WITH samples AS (
            SELECT
                s.side,
                s.geom
            FROM public.fn_door_side_sample_points(
                p_door_geom,
                v_offset
            ) s
        ),

        side_area AS (
            SELECT DISTINCT ON (s.side)
                s.side,
                a.id::bigint AS area_id,
                public.fn_area_routing_role(
                    a.area_type,
                    COALESCE(a.attrs, '{}'::jsonb)
                )::text AS routing_role,
                ST_Area(a.geom)::double precision AS area_m2
            FROM samples s
            JOIN public.areas a
              ON a.floor = p_floor
             AND ST_Covers(a.geom, s.geom)
            ORDER BY
                s.side,
                CASE public.fn_area_routing_role(a.area_type, COALESCE(a.attrs, '{}'::jsonb))
                    WHEN 'routable' THEN 1
                    WHEN 'doorstep_only' THEN 2
                    WHEN 'transparent' THEN 3
                    ELSE 9
                END,
                ST_Area(a.geom) ASC
        ),

        pivoted AS (
            SELECT
                max(area_id) FILTER (WHERE side = 'left')::bigint AS l_area,
                max(area_id) FILTER (WHERE side = 'right')::bigint AS r_area,
                max(routing_role) FILTER (WHERE side = 'left')::text AS l_role,
                max(routing_role) FILTER (WHERE side = 'right')::text AS r_role
            FROM side_area
        ),

        resolved AS (
            SELECT
                CASE
                    WHEN l_area IS NULL OR r_area IS NULL THEN NULL::bigint
                    WHEN l_area = r_area THEN NULL::bigint

                    WHEN l_role = 'routable' AND r_role <> 'routable' THEN l_area
                    WHEN r_role = 'routable' AND l_role <> 'routable' THEN r_area

                    WHEN l_area < r_area THEN l_area
                    ELSE r_area
                END::bigint AS f_area,

                CASE
                    WHEN l_area IS NULL OR r_area IS NULL THEN NULL::bigint
                    WHEN l_area = r_area THEN NULL::bigint

                    WHEN l_role = 'routable' AND r_role <> 'routable' THEN r_area
                    WHEN r_role = 'routable' AND l_role <> 'routable' THEN l_area

                    WHEN l_area < r_area THEN r_area
                    ELSE l_area
                END::bigint AS t_area,

                l_area::bigint AS l_area,
                r_area::bigint AS r_area,
                l_role::text AS l_role,
                r_role::text AS r_role
            FROM pivoted
        )

        SELECT
            r.f_area::bigint AS from_area,
            r.t_area::bigint AS to_area,
            r.l_area::bigint AS left_area,
            r.r_area::bigint AS right_area,
            r.l_role::text AS left_role,
            r.r_role::text AS right_role,
            v_offset::double precision AS used_offset,
            CASE
                WHEN r.f_area IS NOT NULL AND r.t_area IS NOT NULL THEN
                    CASE
                        WHEN v_offset <= 0.60 THEN 0.99::double precision
                        WHEN v_offset <= 0.80 THEN 0.97::double precision
                        WHEN v_offset <= 1.00 THEN 0.95::double precision
                        ELSE 0.92::double precision
                    END
                ELSE 0.0::double precision
            END::double precision AS confidence,
            CASE
                WHEN r.l_area IS NULL OR r.r_area IS NULL THEN 'missing_one_side'
                WHEN r.l_area = r.r_area THEN 'same_area_both_sides'
                WHEN r.f_area IS NOT NULL AND r.t_area IS NOT NULL THEN 'resolved_by_side_samples'
                ELSE 'unresolved'
            END::text AS status
        FROM resolved r
        WHERE r.f_area IS NOT NULL
          AND r.t_area IS NOT NULL
        LIMIT 1;

        IF FOUND THEN
            RETURN;
        END IF;

    END LOOP;

    RETURN QUERY
    SELECT
        NULL::bigint,
        NULL::bigint,
        NULL::bigint,
        NULL::bigint,
        NULL::text,
        NULL::text,
        NULL::double precision,
        0.0::double precision,
        'unresolved_by_side_samples'::text;
END;
$$;


--
-- Name: fn_resolve_location(text, bigint, text, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_resolve_location(p_type text, p_id bigint, p_code text, p_lon double precision, p_lat double precision) RETURNS public.location_ref
    LANGUAGE plpgsql
    AS $$
DECLARE
  res location_ref;
BEGIN
  IF p_type = 'poi' THEN
    SELECT 'poi_points', id, floor,
           ST_Transform(geom, 32640)
    INTO  res.entity_table, res.entity_id, res.floor, res.geom
    FROM poi_points
    WHERE id = p_id;

  ELSIF p_type = 'door' THEN
    SELECT 'doors', id, floor,
           ST_Transform(ST_LineInterpolatePoint(geom, 0.5), 32640)
    INTO  res.entity_table, res.entity_id, res.floor, res.geom
    FROM doors
    WHERE id = p_id;

  ELSIF p_type = 'area' THEN
    SELECT 'areas', id, floor,
           ST_Transform(ST_PointOnSurface(geom), 32640)
    INTO  res.entity_table, res.entity_id, res.floor, res.geom
    FROM areas
    WHERE id = p_id;

  ELSIF p_type = 'qrcode' THEN
    -- ساختار جدول qrcodes رو با دیتابیس خودت هماهنگ کن
    SELECT 'qrcodes', q.id,
           COALESCE((q.attrs->>'floor')::smallint, 0),
           ST_Transform(q.geom, 32640)
    INTO  res.entity_table, res.entity_id, res.floor, res.geom
    FROM qrcodes q
    WHERE q.code = p_code
      AND q.is_active = TRUE;

  ELSIF p_type = 'coordinate' THEN
    res.entity_table := 'coordinate';
    res.entity_id    := NULL;
    res.floor        := 0; -- یا می‌تونی نزدیک‌ترین area رو پیدا کنی و floor اون رو ست کنی
    res.geom         := ST_Transform(
                          ST_SetSRID(ST_MakePoint(p_lon, p_lat), 4326),
                          32640
                        );
  ELSE
    RAISE EXCEPTION 'Unsupported location type: %', p_type;
  END IF;

  IF res.geom IS NULL THEN
    RAISE EXCEPTION 'Location not found for type=% id=% code=%', p_type, p_id, p_code;
  END IF;

  RETURN res;
END;
$$;


--
-- Name: fn_route(timestamp with time zone, public.gender_enum, text, text, bigint, text, double precision, double precision, text, bigint, text, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_origin_type text, p_origin_id bigint, p_origin_code text, p_origin_x double precision, p_origin_y double precision, p_dest_type text, p_dest_id bigint, p_dest_code text, p_dest_x double precision, p_dest_y double precision) RETURNS SETOF public.route_segment
    LANGUAGE plpgsql
    AS $$
DECLARE
  loc_o location_ref;
  loc_d location_ref;
BEGIN
  -- ۱) تبدیل location های ورودی به geometry + floor (همگی در 32640)
  loc_o := fn_resolve_location(
             p_origin_type,
             p_origin_id,
             p_origin_code,
             p_origin_x,
             p_origin_y
           );

  loc_d := fn_resolve_location(
             p_dest_type,
             p_dest_id,
             p_dest_code,
             p_dest_x,
             p_dest_y
           );

  IF loc_o.geom IS NULL THEN
    RAISE EXCEPTION 'Origin location not found';
  END IF;

  IF loc_d.geom IS NULL THEN
    RAISE EXCEPTION 'Destination location not found';
  END IF;

  -- فعلاً فقط مسیر روی یک طبقه را ساپورت می‌کنیم
  IF loc_o.floor <> loc_d.floor THEN
    RAISE EXCEPTION 'Cross-floor routing is not supported yet (origin floor=%, dest floor=%)',
      loc_o.floor, loc_d.floor;
  END IF;

  -- ۲) فقط خروجی NavMesh را پاس بده، بدون هیچ دستکاری هندسه
  RETURN QUERY
    SELECT *
    FROM fn_route_walk_navmesh(
      p_now,
      p_gender,
      p_mode,
      loc_o.floor,
      loc_o.geom,
      loc_d.geom
    );

END;
$$;


--
-- Name: fn_route_analyze_walk(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry, public.lang_enum, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_analyze_walk(p_ts timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry, p_lang public.lang_enum, p_max_alternatives integer DEFAULT 2) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_path_count  integer;
  v_main_geom   geometry(LineString, 32640);
  v_main_json   jsonb;
  v_main_meta   jsonb;

  v_alts        jsonb := '[]'::jsonb;

  v_rec         RECORD;

  -- تنظیمات انتخاب alternative
  v_candidates integer;
  v_tol_m double precision := 1.5;        -- تلورانس بافر برای overlap
  v_max_overlap double precision := 0.85;  -- اگر بالاتر باشد، خیلی شبیه اصلی است
  v_min_hausdorff double precision := 4.0; -- اگر کمتر باشد، انحراف کم است
BEGIN
  --------------------------------------------------------------------
  -- 1) محاسبه مسیرها با visibility + pgr_ksp
  --    تغییر: به‌جای (N+1) تعداد کاندید بیشتری می‌گیریم
  --------------------------------------------------------------------
  -- برای خروجی ۳ مسیر، ۴ یا ۵ کاندید کافی است؛ نه ۱۰ کاندید.
IF COALESCE(p_max_alternatives, 2) <= 0 THEN
  v_candidates := 1;
ELSE
  v_candidates := LEAST(
    GREATEST(COALESCE(p_max_alternatives, 2) + 2, 3),
    5
  );
END IF;

  DROP TABLE IF EXISTS tmp_paths_vis;
  CREATE TEMP TABLE tmp_paths_vis AS
  SELECT *
  FROM public.fn_route_walk_visibility_k(
         p_ts::timestamptz,
         p_gender::gender_enum,
         p_mode::text,
         p_floor::smallint,
         p_origin::geometry,
         p_dest::geometry,
         v_candidates
       );
			 
			 
 DROP TABLE IF EXISTS tmp_paths_vis_limited;

CREATE TEMP TABLE tmp_paths_vis_limited AS
SELECT *
FROM tmp_paths_vis
ORDER BY path_rank ASC
LIMIT v_candidates;

DROP TABLE tmp_paths_vis;

ALTER TABLE tmp_paths_vis_limited RENAME TO tmp_paths_vis;

  SELECT COUNT(*) INTO v_path_count FROM tmp_paths_vis;

  IF v_path_count = 0 THEN
    RETURN jsonb_build_object(
      'status',       'NO_PATH',
      'message',      'هیچ مسیر قابل دسترسی بین مبدا و مقصد یافت نشد',
      'source',       'computed',
      'steps',        '[]'::jsonb,
      'sahns',        '[]'::jsonb,
      'viaPoints',    '[]'::jsonb,
      'alternatives', '[]'::jsonb
    );
  END IF;

  --------------------------------------------------------------------
  -- 2) ددیوپ کردن مسیرها بر اساس هندسه (همان منطق قبلی)
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_paths_dedup;
  CREATE TEMP TABLE tmp_paths_dedup AS
  SELECT DISTINCT ON (
    ST_AsBinary(
      ST_LineMerge(
        ST_SnapToGrid(geom, 0.01)
      )
    )
  )
    path_rank,
    geom,
		COALESCE(meta, '{}'::jsonb) AS meta
  FROM tmp_paths_vis
  ORDER BY
    ST_AsBinary(ST_LineMerge(ST_SnapToGrid(geom, 0.01))),
    path_rank;

  SELECT COUNT(*) INTO v_path_count FROM tmp_paths_dedup;

  IF v_path_count = 0 THEN
    RETURN jsonb_build_object(
      'status',       'NO_PATH',
      'message',      'هیچ مسیر قابل دسترسی بعد از ددیوپ یافت نشد',
      'source',       'computed',
      'steps',        '[]'::jsonb,
      'sahns',        '[]'::jsonb,
      'viaPoints',    '[]'::jsonb,
      'alternatives', '[]'::jsonb
    );
  END IF;

  --------------------------------------------------------------------
  -- 3) مسیر اصلی (بهترین مسیر بعد از ددیوپ)
  --------------------------------------------------------------------
  SELECT geom, COALESCE(meta->'path_edges', '[]'::jsonb)
INTO v_main_geom, v_main_meta
FROM tmp_paths_dedup
ORDER BY path_rank
LIMIT 1;

v_main_json := fn_build_route_json(
  v_main_geom,
  p_floor,
  p_mode,
  p_lang,
  'computed',
  COALESCE(v_main_meta, '[]'::jsonb)
);

  --------------------------------------------------------------------
  -- 4) اسکوردهی به کاندیدهای alternative و انتخاب متفاوت‌ترین‌ها
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_alt_scored;
  CREATE TEMP TABLE tmp_alt_scored AS
  SELECT
    d.path_rank,
    d.geom,
		COALESCE(d.meta->'path_edges', '[]'::jsonb) AS path_edges,
--     public.fn_route_overlap_ratio(v_main_geom, d.geom, v_tol_m) AS overlap_ratio,
    public.fn_route_middle_overlap_ratio(v_main_geom, d.geom,0.15, 0.15, v_tol_m) AS overlap_ratio,
    ST_HausdorffDistance(
      ST_LineMerge(v_main_geom),
      ST_LineMerge(d.geom)
    ) AS hausdorff_m
  FROM tmp_paths_dedup d
  WHERE d.path_rank > 1
    AND NOT ST_Equals(
      ST_SnapToGrid(d.geom, 0.01),
      ST_SnapToGrid(v_main_geom, 0.01)
    );

  -- انتخاب: کمترین overlap + بیشترین انحراف
  v_alts := '[]'::jsonb;

  FOR v_rec IN
    SELECT path_rank, geom, path_edges, overlap_ratio, hausdorff_m
    FROM tmp_alt_scored
    WHERE overlap_ratio <= v_max_overlap
      AND hausdorff_m >= v_min_hausdorff
--     ORDER BY overlap_ratio ASC, hausdorff_m DESC, path_rank ASC
		ORDER BY
  (
    path_rank * 0.60
    + overlap_ratio * 10.0
    + LEAST(hausdorff_m, 30.0) * 0.05
  ) ASC
  LOOP
    IF jsonb_array_length(v_alts) >= p_max_alternatives THEN
      EXIT;
    END IF;

    v_alts :=
  v_alts || jsonb_build_array(
    fn_build_route_json(
      v_rec.geom,
      p_floor,
      p_mode,
      p_lang,
      'computed',
      COALESCE(v_rec.path_edges, '[]'::jsonb)
    ) || jsonb_build_object(
      'similarity', jsonb_build_object(
        'overlap_ratio', round(v_rec.overlap_ratio::numeric, 3),
        'hausdorff_m',   round(v_rec.hausdorff_m::numeric, 2),
        'tol_m',         v_tol_m
      )
    )
  );
  END LOOP;

  --------------------------------------------------------------------
  -- 4.1) اگر با فیلتر سخت‌گیرانه چیزی درنیامد، یک fallback نرم‌تر
  --      (باز هم متفاوت‌ترین‌ها را انتخاب می‌کنیم)
  --------------------------------------------------------------------
  IF jsonb_array_length(v_alts) = 0 THEN
    FOR v_rec IN
  SELECT path_rank, geom, path_edges, overlap_ratio, hausdorff_m
  FROM tmp_alt_scored
  ORDER BY overlap_ratio ASC, hausdorff_m DESC, path_rank ASC
LOOP
      IF jsonb_array_length(v_alts) >= p_max_alternatives THEN
        EXIT;
      END IF;

      v_alts :=
        v_alts || jsonb_build_array(
          fn_build_route_json(
  v_rec.geom,
  p_floor,
  p_mode,
  p_lang,
  'computed',
  COALESCE(v_rec.path_edges, '[]'::jsonb)
) || jsonb_build_object(
            'similarity', jsonb_build_object(
              'overlap_ratio', round(v_rec.overlap_ratio::numeric, 3),
              'hausdorff_m',   round(v_rec.hausdorff_m::numeric, 2),
              'tol_m',         v_tol_m,
              'note',          'fallback_soft_ranking'
            )
          )
        );
    END LOOP;
  END IF;

  --------------------------------------------------------------------
  -- 5) اضافه کردن alternatives به main
  --------------------------------------------------------------------
  v_main_json :=
    v_main_json || jsonb_build_object(
      'alternatives', v_alts,
      'alt_policy', jsonb_build_object(
        'candidates', v_candidates,
        'tol_m', v_tol_m,
        'max_overlap', v_max_overlap,
        'min_hausdorff_m', v_min_hausdorff
      )
    );

  RETURN v_main_json;
END;
$$;


--
-- Name: fn_route_geo(timestamp with time zone, public.gender_enum, text, text, bigint, text, double precision, double precision, text, bigint, text, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_geo(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_origin_type text, p_origin_id bigint, p_origin_code text, p_origin_x double precision, p_origin_y double precision, p_dest_type text, p_dest_id bigint, p_dest_code text, p_dest_x double precision, p_dest_y double precision) RETURNS SETOF public.route_segment_geo
    LANGUAGE sql
    AS $$
  SELECT
    r.seq,
    ST_Transform(r.geom, 4326) AS geom,
    r.mode,
    r.floor,
    r.distance_m,
    r.duration_s,
    r.meta
  FROM fn_route(
    p_now,
    p_gender,
    p_mode,
    p_origin_type,
    p_origin_id,
    p_origin_code,
    p_origin_x,
    p_origin_y,
    p_dest_type,
    p_dest_id,
    p_dest_code,
    p_dest_x,
    p_dest_y
  ) AS r;
$$;


--
-- Name: fn_route_line_valid_inside_area(bigint, public.geometry, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_line_valid_inside_area(p_area_id bigint, p_line public.geometry, p_tol_m double precision DEFAULT 0.20) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_area geometry;
  v_tol double precision;
BEGIN
  IF p_area_id IS NULL
     OR p_line IS NULL
     OR ST_IsEmpty(p_line)
  THEN
    RETURN false;
  END IF;

  v_tol := GREATEST(COALESCE(p_tol_m, 0.0), 0.0);

  SELECT ST_MakeValid(a.geom)
  INTO v_area
  FROM public.areas a
  WHERE a.id = p_area_id;

  IF v_area IS NULL OR ST_IsEmpty(v_area) THEN
    RETURN false;
  END IF;

  /*
    قانون صحیح برای routing از DAP:
    اگر خط داخل area یا روی مرز area باشد معتبر است.
    buffer مثبت فقط برای خطاهای ریز توپولوژی است.
    buffer منفی برای DAP اشتباه است چون DAPها نزدیک مرز ساخته می‌شوند.
  */
  RETURN ST_CoveredBy(
    p_line,
    ST_Buffer(v_area, v_tol)
  );
END;
$$;


--
-- Name: fn_route_line_valid_inside_area_dap_connector(bigint, public.geometry, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_line_valid_inside_area_dap_connector(p_area_id bigint, p_line public.geometry, p_clearance_m double precision DEFAULT 0.20, p_boundary_tol_m double precision DEFAULT 0.08) RETURNS boolean
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    v_area geometry;
    v_line geometry(LineString, 32640);
    v_len double precision;
    v_trim_start double precision;
    v_trim_end double precision;
    v_mid_part geometry;
BEGIN
    IF p_line IS NULL OR ST_IsEmpty(p_line) THEN
        RETURN false;
    END IF;

    SELECT a.geom
    INTO v_area
    FROM public.areas a
    WHERE a.id = p_area_id;

    IF v_area IS NULL OR ST_IsEmpty(v_area) THEN
        RETURN false;
    END IF;

    v_line := ST_LineMerge(
        ST_CollectionExtract(ST_Force2D(p_line), 2)
    )::geometry(LineString, 32640);

    IF v_line IS NULL OR ST_IsEmpty(v_line) THEN
        RETURN false;
    END IF;

    v_len := ST_Length(v_line);

    IF v_len IS NULL OR v_len <= 0.05 THEN
        RETURN false;
    END IF;

    --------------------------------------------------------------------
    -- شرط اصلی ایمنی:
    -- کل connector باید داخل خود area یا تلورانس خیلی کوچک مرز باشد.
    -- این اجازه خروج از محدوده‌های دیگر را نمی‌دهد.
    --------------------------------------------------------------------
    IF NOT ST_CoveredBy(v_line, ST_Buffer(v_area, p_boundary_tol_m)) THEN
        RETURN false;
    END IF;

    --------------------------------------------------------------------
    -- برای خط‌های خیلی کوتاه، همین شرط کافی است.
    --------------------------------------------------------------------
    IF v_len <= 1.00 THEN
        RETURN true;
    END IF;

    --------------------------------------------------------------------
    -- برای خط‌های بلندتر:
    -- بخش مرکزی مسیر باید داخل area باشد.
    -- اما ابتدا/انتهای خط می‌تواند روی مرز باشد، چون DAP دقیقاً مرزی است.
    --------------------------------------------------------------------
    v_trim_start := LEAST(0.15, 0.50 / v_len);
    v_trim_end   := GREATEST(0.85, 1.00 - (0.50 / v_len));

    IF v_trim_start >= v_trim_end THEN
        RETURN true;
    END IF;

    v_mid_part := ST_LineSubstring(v_line, v_trim_start, v_trim_end);

    IF v_mid_part IS NULL OR ST_IsEmpty(v_mid_part) THEN
        RETURN true;
    END IF;

    RETURN ST_CoveredBy(
        v_mid_part,
        ST_Buffer(v_area, GREATEST(0.02, p_boundary_tol_m))
    );
END;
$$;


--
-- Name: fn_route_middle_overlap_ratio(public.geometry, public.geometry, double precision, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_middle_overlap_ratio(p_main public.geometry, p_alt public.geometry, p_start_ignore double precision DEFAULT 0.15, p_end_ignore double precision DEFAULT 0.15, p_tol_m double precision DEFAULT 1.5) RETURNS double precision
    LANGUAGE sql IMMUTABLE
    AS $$
  WITH m AS (
    SELECT
      ST_LineSubstring(
        ST_LineMerge(p_main)::geometry(LineString, 32640),
        LEAST(GREATEST(p_start_ignore, 0.0), 0.45),
        GREATEST(LEAST(1.0 - p_end_ignore, 1.0), 0.55)
      ) AS geom
  ),
  a AS (
    SELECT ST_LineMerge(p_alt)::geometry(LineString, 32640) AS geom
  ),
  x AS (
    SELECT
      ST_Length(a.geom) AS alt_len,
      ST_Length(
        ST_Intersection(
          a.geom,
          ST_Buffer(m.geom, p_tol_m)
        )
      ) AS overlap_len
    FROM a, m
    WHERE a.geom IS NOT NULL
      AND m.geom IS NOT NULL
      AND NOT ST_IsEmpty(a.geom)
      AND NOT ST_IsEmpty(m.geom)
  )
  SELECT
    CASE
      WHEN alt_len IS NULL OR alt_len <= 0 THEN 1.0
      ELSE LEAST(1.0, GREATEST(0.0, overlap_len / alt_len))
    END
  FROM x;
$$;


--
-- Name: fn_route_overlap_ratio(public.geometry, public.geometry, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_overlap_ratio(p_main public.geometry, p_alt public.geometry, p_tol_m double precision DEFAULT 1.5) RETURNS double precision
    LANGUAGE plpgsql IMMUTABLE
    AS $$
DECLARE
  v_main geometry;
  v_alt  geometry;
  v_alt_len double precision;
  v_inter_len double precision;
BEGIN
  IF p_main IS NULL OR p_alt IS NULL THEN
    RETURN 1.0;
  END IF;

  v_main := ST_LineMerge(p_main);
  v_alt  := ST_LineMerge(p_alt);

  v_alt_len := ST_Length(v_alt);
  IF v_alt_len IS NULL OR v_alt_len <= 0 THEN
    RETURN 1.0;
  END IF;

  -- بافر روی مسیر اصلی (تلورانس بر حسب متر؛ SRID شما 32640 است)
  v_inter_len := ST_Length(
    ST_Intersection(
      ST_Buffer(v_main, p_tol_m),
      v_alt
    )
  );

  IF v_inter_len IS NULL THEN
    v_inter_len := 0;
  END IF;

  RETURN LEAST(GREATEST(v_inter_len / v_alt_len, 0.0), 1.0);
END;
$$;


--
-- Name: fn_route_point_area_id(timestamp with time zone, public.gender_enum, text, smallint, public.geometry); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_point_area_id(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_point public.geometry) RETURNS bigint
    LANGUAGE sql STABLE
    AS $$
  SELECT aa.id
  FROM public.fn_allowed_areas(p_now, p_gender, p_mode, p_floor) aa
  WHERE aa.is_allowed = true
    AND ST_Covers(ST_Buffer(aa.geom, 0.05), p_point)
  ORDER BY ST_Area(aa.geom) ASC
  LIMIT 1;
$$;


--
-- Name: fn_route_smooth_inside_area(bigint, public.geometry, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_smooth_inside_area(p_area_id bigint, p_geom public.geometry, p_tol_m double precision DEFAULT 1.20, p_check_tol_m double precision DEFAULT 0.35) RETURNS public.geometry
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_try geometry(LineString, 32640);
  v_tol double precision;
BEGIN
  IF p_area_id IS NULL OR p_geom IS NULL OR ST_IsEmpty(p_geom) THEN
    RETURN p_geom;
  END IF;

  IF GeometryType(p_geom) <> 'LINESTRING' THEN
    RETURN p_geom;
  END IF;

  -- از نرم‌سازی قوی به ضعیف؛ هرکدام داخل safe area ماند، قبول شود
  FOREACH v_tol IN ARRAY ARRAY[
    p_tol_m,
    p_tol_m * 0.75,
    p_tol_m * 0.50,
    p_tol_m * 0.25,
    0.20
  ]
  LOOP
    v_try := ST_LineMerge(
               ST_SimplifyPreserveTopology(
                 ST_RemoveRepeatedPoints(p_geom, 0.05),
                 GREATEST(v_tol, 0.05)
               )
             )::geometry(LineString, 32640);

    IF v_try IS NOT NULL
       AND NOT ST_IsEmpty(v_try)
       AND ST_NPoints(v_try) >= 2
       AND public.fn_route_line_valid_inside_area(p_area_id, v_try, p_check_tol_m)
    THEN
      RETURN v_try;
    END IF;
  END LOOP;

  -- اگر هیچ نرم‌سازی امن نبود، همان مسیر خام را برگردان؛ نه مسیر خارج از محدوده
  RETURN ST_RemoveRepeatedPoints(p_geom, 0.03)::geometry(LineString, 32640);
END;
$$;


--
-- Name: fn_route_smooth_polyline_inside_area(bigint, public.geometry, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_smooth_polyline_inside_area(p_area_id bigint, p_line public.geometry, p_tol_m double precision DEFAULT 0.20) RETURNS public.geometry
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  v_line geometry(LineString, 32640);
  v_n integer;
  v_i integer;
  v_j integer;
  v_next integer;
  v_pts geometry[];
  v_seg geometry(LineString, 32640);
  v_out geometry(LineString, 32640);
BEGIN
  IF p_area_id IS NULL OR p_line IS NULL OR ST_IsEmpty(p_line) THEN
    RETURN NULL;
  END IF;

  IF GeometryType(p_line) NOT IN ('LINESTRING', 'ST_LineString') THEN
    RETURN p_line;
  END IF;

  v_line :=
    CASE
      WHEN ST_SRID(p_line) = 32640 THEN p_line::geometry(LineString, 32640)
      ELSE ST_Transform(p_line, 32640)::geometry(LineString, 32640)
    END;

  v_line := ST_RemoveRepeatedPoints(v_line, 0.01)::geometry(LineString, 32640);
  v_n := ST_NPoints(v_line);

  IF v_n IS NULL OR v_n < 3 THEN
    RETURN v_line;
  END IF;

  v_pts := ARRAY[ST_PointN(v_line, 1)::geometry(Point, 32640)];
  v_i := 1;

  WHILE v_i < v_n LOOP
    v_next := v_i + 1;

    -- از دورترین نقطه ممکن به عقب برگرد؛ هر جا segment معتبر بود، همان را انتخاب کن
    FOR v_j IN REVERSE v_n..(v_i + 1) LOOP
      v_seg := ST_MakeLine(
        ST_PointN(v_line, v_i)::geometry(Point, 32640),
        ST_PointN(v_line, v_j)::geometry(Point, 32640)
      )::geometry(LineString, 32640);

      IF public.fn_route_line_valid_inside_area(p_area_id, v_seg, p_tol_m) THEN
        v_next := v_j;
        EXIT;
      END IF;
    END LOOP;

    v_pts := v_pts || ST_PointN(v_line, v_next)::geometry(Point, 32640);
    v_i := v_next;
  END LOOP;

  v_out := ST_RemoveRepeatedPoints(ST_MakeLine(v_pts), 0.01)::geometry(LineString, 32640);

  IF public.fn_route_line_valid_inside_area(p_area_id, v_out, p_tol_m) THEN
    RETURN v_out;
  END IF;

  RETURN v_line;
END;
$$;


--
-- Name: fn_route_walk_pgr(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_walk_pgr(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry) RETURNS TABLE(seq integer, geom public.geometry, cost numeric)
    LANGUAGE plpgsql
    AS $_$
DECLARE
  v_origin_node bigint;
  v_dest_node   bigint;

  v_status    text;
  v_details   jsonb;
  v_sql_edges text;

  v_line_geom geometry(LineString, 32640);
  v_line_cost numeric;
BEGIN
  --------------------------------------------------------------------
  -- 1) نزدیک‌ترین نود به مبدأ
  --------------------------------------------------------------------
  SELECT n.id
  INTO v_origin_node
  FROM routing_nodes n
  WHERE n.floor = p_floor
  ORDER BY n.geom <-> p_origin
  LIMIT 1;

  IF v_origin_node IS NULL THEN
    v_status  := 'NO_ORIGIN_NODE';
    v_details := jsonb_build_object(
      'msg', 'هیچ نود مسیریابی نزدیک به مبدأ پیدا نشد',
      'floor', p_floor
    );
    PERFORM fn_log_route_debug(
      'pgr_dijkstra',
      p_mode,
      p_gender,
      p_floor,
      p_origin,
      p_dest,
      NULL,
      NULL,
      NULL,
      v_status,
      v_details
    );
    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 2) نزدیک‌ترین نود به مقصد
  --------------------------------------------------------------------
  SELECT n.id
  INTO v_dest_node
  FROM routing_nodes n
  WHERE n.floor = p_floor
  ORDER BY n.geom <-> p_dest
  LIMIT 1;

  IF v_dest_node IS NULL THEN
    v_status  := 'NO_DEST_NODE';
    v_details := jsonb_build_object(
      'msg', 'هیچ نود مسیریابی نزدیک به مقصد پیدا نشد',
      'floor', p_floor
    );
    PERFORM fn_log_route_debug(
      'pgr_dijkstra',
      p_mode,
      p_gender,
      p_floor,
      p_origin,
      p_dest,
      v_origin_node,
      NULL,
      NULL,
      v_status,
      v_details
    );
    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 3) تعریف گراف برای pgRouting
  --    🔴 نکته: این‌جا src/dst را به source/target alias کردیم
  --------------------------------------------------------------------
  v_sql_edges := format($$
    SELECT
      row_number() OVER () AS id,
      src    AS source,
      dst    AS target,
      cost::float8      AS cost,
      cost::float8      AS reverse_cost
    FROM routing_edges_live
    WHERE floor = %s
  $$, p_floor);

  --------------------------------------------------------------------
  -- 4) اجرای pgr_dijkstra داخل جدول موقت
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_route_raw;
  CREATE TEMP TABLE tmp_route_raw AS
  SELECT *
  FROM pgr_dijkstra(
    v_sql_edges,
    v_origin_node,
    v_dest_node,
    directed := false
  );

  -- اگر هیچ ردیفی نیست → NO_PATH
  IF NOT EXISTS (SELECT 1 FROM tmp_route_raw) THEN
    v_status := 'NO_PATH';
    v_details := jsonb_build_object(
      'msg', 'هیچ مسیر پیدا نشد (pgr_dijkstra)',
      'origin_node', v_origin_node,
      'dest_node',   v_dest_node
    );
    PERFORM fn_log_route_debug(
      'pgr_dijkstra',
      p_mode,
      p_gender,
      p_floor,
      p_origin,
      p_dest,
      v_origin_node,
      v_dest_node,
      NULL,
      v_status,
      v_details
    );
    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 5) join با routing_nodes و ساخت LineString
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_route_nodes;
  CREATE TEMP TABLE tmp_route_nodes AS
  SELECT
    r.seq        AS rn_seq,
    r.node       AS rn_node,
    r.agg_cost   AS rn_agg_cost,
    n.geom       AS rn_geom
  FROM tmp_route_raw r
  JOIN routing_nodes n
    ON n.id = r.node
  ORDER BY r.seq;

  -- ساخت خط و هزینه کل
  SELECT
    ST_MakeLine(rn_geom ORDER BY rn_seq)::geometry(LineString, 32640) AS line_geom,
    max(rn_agg_cost)::numeric                                         AS line_cost
  INTO
    v_line_geom,
    v_line_cost
  FROM tmp_route_nodes;

  --------------------------------------------------------------------
  -- 6) لاگ موفق
  --------------------------------------------------------------------
  v_status := 'OK';
  v_details := jsonb_build_object(
    'msg', 'مسیر با pgr_dijkstra پیدا شد',
    'origin_node', v_origin_node,
    'dest_node',   v_dest_node
  );
  PERFORM fn_log_route_debug(
    'pgr_dijkstra',
    p_mode,
    p_gender,
    p_floor,
    p_origin,
    p_dest,
    v_origin_node,
    v_dest_node,
    v_dest_node,
    v_status,
    v_details
  );

  --------------------------------------------------------------------
  -- 7) خروجی نهایی
  --------------------------------------------------------------------
  seq  := 1;
  geom := v_line_geom;
  cost := v_line_cost;
  RETURN NEXT;

  RETURN;
END;
$_$;


--
-- Name: fn_route_walk_pgr_k(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_walk_pgr_k(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry, p_k integer DEFAULT 3) RETURNS TABLE(path_rank integer, geom public.geometry, cost numeric, path_edges jsonb)
    LANGUAGE plpgsql
    AS $_$
DECLARE
  v_origin_virtual bigint := -1000000001;
  v_dest_virtual   bigint := -1000000002;

  v_origin_area_id bigint;
  v_dest_area_id   bigint;

  v_i int;
  v_k int := LEAST(GREATEST(COALESCE(p_k, 1), 1), 10);

  v_sql text;
  v_has_path boolean;

  v_line_geom geometry(LineString, 32640);
  v_node_line geometry(LineString, 32640);
  v_line_cost numeric;
  v_path_edges jsonb;

  v_candidate_limit integer := 20;
  v_candidate_radius_m double precision := 200.0;
  v_clearance_m double precision := 0.20;

  v_start_connector_count integer := 0;
  v_dest_connector_count integer := 0;
BEGIN
  --------------------------------------------------------------------
  -- 0) نرمال‌سازی SRID
  --------------------------------------------------------------------
  IF p_origin IS NULL OR p_dest IS NULL THEN
    RETURN;
  END IF;

  IF ST_SRID(p_origin) <> 32640 THEN
    p_origin := ST_Transform(p_origin, 32640);
  END IF;

  IF ST_SRID(p_dest) <> 32640 THEN
    p_dest := ST_Transform(p_dest, 32640);
  END IF;

  --------------------------------------------------------------------
  -- 1) تشخیص area مبدأ و مقصد از allowed areas
  --------------------------------------------------------------------
  v_origin_area_id := public.fn_route_point_area_id(
    p_now,
    p_gender,
    p_mode,
    p_floor,
    p_origin
  );

  v_dest_area_id := public.fn_route_point_area_id(
    p_now,
    p_gender,
    p_mode,
    p_floor,
    p_dest
  );

  IF v_origin_area_id IS NULL OR v_dest_area_id IS NULL THEN
    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 2) edgeهای live اصلی
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_edges_live;

  CREATE TEMP TABLE tmp_edges_live AS
  SELECT
    e.id::bigint AS edge_id,
    e.src::bigint AS source,
    e.dst::bigint AS target,
    e.cost::float8 AS cost,
    e.reverse_cost::float8 AS reverse_cost,
    e.geom::geometry(LineString, 32640) AS edge_geom,
    e.door_id::bigint AS door_id,
    1.0::float8 AS penalty_w,
    'graph'::text AS edge_type
  FROM public.fn_routing_edges_live_param(
    p_now,
    p_gender,
    p_mode,
    p_floor
  ) e
  WHERE e.cost > 0
    AND e.geom IS NOT NULL
    AND NOT ST_IsEmpty(e.geom);

  CREATE INDEX ON tmp_edges_live(edge_id);
  CREATE INDEX ON tmp_edges_live(source);
  CREATE INDEX ON tmp_edges_live(target);

  --------------------------------------------------------------------
  -- 3) نودهای live
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_live_nodes;

  CREATE TEMP TABLE tmp_live_nodes AS
  SELECT DISTINCT source AS node_id
  FROM tmp_edges_live

  UNION

  SELECT DISTINCT target AS node_id
  FROM tmp_edges_live;

  CREATE INDEX ON tmp_live_nodes(node_id);

  --------------------------------------------------------------------
  -- 4) اتصال‌های مجازی مبدأ
  -- نکته: دیگر nearest-node تک‌گزینه‌ای نداریم.
  --------------------------------------------------------------------
  WITH start_candidates AS (
    SELECT
      rn.id AS node_id,
      rn.geom::geometry(Point, 32640) AS node_geom,
      rn.area_id,
      ST_Distance(p_origin, rn.geom) AS dist_m,
      public.fn_build_intra_area_edge_geom(
        rn.area_id,
        p_floor,
        p_origin,
        rn.geom,
        v_clearance_m
      ) AS connector_geom
    FROM public.routing_nodes rn
    JOIN tmp_live_nodes ln
      ON ln.node_id = rn.id
    WHERE rn.floor = p_floor
      AND rn.area_id = v_origin_area_id
      AND rn.ref_table = 'door_access_points'
      AND ST_DWithin(p_origin, rn.geom, v_candidate_radius_m)
    ORDER BY p_origin <-> rn.geom
    LIMIT v_candidate_limit
  ),
  valid_start AS (
    SELECT
      sc.*,
      ST_Length(sc.connector_geom) AS len_m,
      public.fn_heading_penalty(
        p_origin,
        sc.node_geom,
        p_dest,
        35.0
      ) AS heading_penalty
    FROM start_candidates sc
    WHERE sc.connector_geom IS NOT NULL
      AND NOT ST_IsEmpty(sc.connector_geom)
      AND public.fn_route_line_valid_inside_area(
        sc.area_id,
        sc.connector_geom,
        v_clearance_m
      )
  )
  INSERT INTO tmp_edges_live(
    edge_id,
    source,
    target,
    cost,
    reverse_cost,
    edge_geom,
    door_id,
    penalty_w,
    edge_type
  )
  SELECT
    (-2000000000 - row_number() OVER ())::bigint AS edge_id,
    v_origin_virtual AS source,
    vs.node_id AS target,
    GREATEST((vs.len_m + vs.heading_penalty)::float8, 0.01) AS cost,
    GREATEST((vs.len_m + vs.heading_penalty)::float8, 0.01) AS reverse_cost,
    vs.connector_geom::geometry(LineString, 32640) AS edge_geom,
    NULL::bigint AS door_id,
    1.0::float8 AS penalty_w,
    'origin_connector'::text AS edge_type
  FROM valid_start vs;

  GET DIAGNOSTICS v_start_connector_count = ROW_COUNT;

  --------------------------------------------------------------------
  -- 5) اتصال‌های مجازی مقصد
  --------------------------------------------------------------------
  WITH dest_candidates AS (
    SELECT
      rn.id AS node_id,
      rn.geom::geometry(Point, 32640) AS node_geom,
      rn.area_id,
      ST_Distance(p_dest, rn.geom) AS dist_m,
      public.fn_build_intra_area_edge_geom(
        rn.area_id,
        p_floor,
        rn.geom,
        p_dest,
        v_clearance_m
      ) AS connector_geom
    FROM public.routing_nodes rn
    JOIN tmp_live_nodes ln
      ON ln.node_id = rn.id
    WHERE rn.floor = p_floor
      AND rn.area_id = v_dest_area_id
      AND rn.ref_table = 'door_access_points'
      AND ST_DWithin(p_dest, rn.geom, v_candidate_radius_m)
    ORDER BY p_dest <-> rn.geom
    LIMIT v_candidate_limit
  ),
  valid_dest AS (
    SELECT
      dc.*,
      ST_Length(dc.connector_geom) AS len_m
    FROM dest_candidates dc
    WHERE dc.connector_geom IS NOT NULL
      AND NOT ST_IsEmpty(dc.connector_geom)
      AND public.fn_route_line_valid_inside_area(
        dc.area_id,
        dc.connector_geom,
        v_clearance_m
      )
  )
  INSERT INTO tmp_edges_live(
    edge_id,
    source,
    target,
    cost,
    reverse_cost,
    edge_geom,
    door_id,
    penalty_w,
    edge_type
  )
  SELECT
    (-2100000000 - row_number() OVER ())::bigint AS edge_id,
    vd.node_id AS source,
    v_dest_virtual AS target,
    GREATEST(vd.len_m::float8, 0.01) AS cost,
    GREATEST(vd.len_m::float8, 0.01) AS reverse_cost,
    vd.connector_geom::geometry(LineString, 32640) AS edge_geom,
    NULL::bigint AS door_id,
    1.0::float8 AS penalty_w,
    'dest_connector'::text AS edge_type
  FROM valid_dest vd;

  GET DIAGNOSTICS v_dest_connector_count = ROW_COUNT;

  --------------------------------------------------------------------
  -- 6) اگر اتصال مجازی ساخته نشد، مسیر نداریم
  --------------------------------------------------------------------
  IF v_start_connector_count = 0 OR v_dest_connector_count = 0 THEN
    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 7) خروجی مسیرها
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_paths_out;

  CREATE TEMP TABLE tmp_paths_out (
    path_rank integer,
    line_geom geometry(LineString, 32640),
    line_cost numeric,
    path_edges jsonb
  ) ON COMMIT DROP;

  --------------------------------------------------------------------
  -- 8) تولید K مسیر
  --------------------------------------------------------------------
  FOR v_i IN 1..v_k LOOP

    v_sql := $q$
      SELECT
        edge_id::bigint AS id,
        source::bigint AS source,
        target::bigint AS target,
        (cost * penalty_w)::float8 AS cost,
        CASE
          WHEN reverse_cost < 0 THEN -1::float8
          ELSE (reverse_cost * penalty_w)::float8
        END AS reverse_cost
      FROM tmp_edges_live
    $q$;

    DROP TABLE IF EXISTS tmp_route_raw;

    CREATE TEMP TABLE tmp_route_raw AS
    SELECT *
    FROM pgr_dijkstra(
      v_sql,
      v_origin_virtual,
      v_dest_virtual,
      directed := true
    )
    WHERE edge <> -1;

    SELECT EXISTS (SELECT 1 FROM tmp_route_raw)
    INTO v_has_path;

    IF NOT v_has_path THEN
      EXIT;
    END IF;

    ------------------------------------------------------------------
    -- 8-الف) edgeهای مسیر با جهت درست
    ------------------------------------------------------------------
    DROP TABLE IF EXISTS tmp_ordered_edges;

    CREATE TEMP TABLE tmp_ordered_edges AS
    SELECT
      rr.seq,
      rr.path_seq,
      rr.node::bigint AS from_node,
      rr.edge::bigint AS edge_id,

      CASE
        WHEN te.source = rr.node THEN te.target
        ELSE te.source
      END::bigint AS to_node,

      te.door_id,
      te.edge_type,

      CASE
        WHEN te.source = rr.node THEN te.edge_geom
        ELSE ST_Reverse(te.edge_geom)
      END::geometry(LineString, 32640) AS edge_geom,

      rr.cost::numeric AS edge_cost
    FROM tmp_route_raw rr
    JOIN tmp_edges_live te
      ON te.edge_id = rr.edge
    ORDER BY rr.path_seq;

    ------------------------------------------------------------------
    -- 8-ب) path_edges برای stepها
    ------------------------------------------------------------------
    SELECT COALESCE(
      jsonb_agg(
        jsonb_build_object(
          'seq', path_seq,
          'edgeId', edge_id,
          'fromNode', from_node,
          'toNode', to_node,
          'doorId', door_id,
          'edgeType', edge_type
        )
        ORDER BY path_seq
      ),
      '[]'::jsonb
    )
    INTO v_path_edges
    FROM tmp_ordered_edges;

    ------------------------------------------------------------------
    -- 8-ج) هزینه مسیر
    ------------------------------------------------------------------
    SELECT COALESCE(SUM(edge_cost), 0)::numeric
    INTO v_line_cost
    FROM tmp_ordered_edges;

    ------------------------------------------------------------------
    -- 8-د) ساخت هندسه از edge.geom
    ------------------------------------------------------------------
    WITH edge_points AS (
      SELECT
        oe.path_seq,
        dp.path[1] AS pt_idx,
        dp.geom::geometry(Point, 32640) AS pt
      FROM tmp_ordered_edges oe
      CROSS JOIN LATERAL ST_DumpPoints(oe.edge_geom) AS dp
    ),
    filtered_points AS (
      SELECT
        ep.path_seq,
        ep.pt_idx,
        ep.pt
      FROM edge_points ep
      WHERE ep.path_seq = 1
         OR ep.pt_idx > 1
    )
    SELECT
      ST_RemoveRepeatedPoints(
        ST_MakeLine(fp.pt ORDER BY fp.path_seq, fp.pt_idx),
        0.01
      )::geometry(LineString, 32640)
    INTO v_line_geom
    FROM filtered_points fp;

    ------------------------------------------------------------------
    -- 8-هـ) fallback هندسی از nodeها اگر edge-geom خراب شد
    ------------------------------------------------------------------
    IF v_line_geom IS NULL
       OR ST_IsEmpty(v_line_geom)
       OR ST_NPoints(v_line_geom) < 2
    THEN
      WITH route_nodes AS (
        SELECT
          rr.seq,
          CASE
            WHEN rr.node = v_origin_virtual THEN p_origin::geometry(Point, 32640)
            WHEN rr.node = v_dest_virtual THEN p_dest::geometry(Point, 32640)
            ELSE rn.geom::geometry(Point, 32640)
          END AS pt
        FROM tmp_route_raw rr
        LEFT JOIN public.routing_nodes rn
          ON rn.id = rr.node
        ORDER BY rr.seq
      )
      SELECT
        ST_RemoveRepeatedPoints(
          ST_MakeLine(rn.pt ORDER BY rn.seq),
          0.01
        )::geometry(LineString, 32640)
      INTO v_node_line
      FROM route_nodes rn
      WHERE rn.pt IS NOT NULL;

      v_line_geom := v_node_line;
    END IF;

    IF v_line_geom IS NULL
       OR ST_IsEmpty(v_line_geom)
       OR ST_NPoints(v_line_geom) < 2
    THEN
      EXIT;
    END IF;

    INSERT INTO tmp_paths_out(
      path_rank,
      line_geom,
      line_cost,
      path_edges
    )
    VALUES (
      v_i,
      v_line_geom,
      COALESCE(v_line_cost, ST_Length(v_line_geom)::numeric),
      COALESCE(v_path_edges, '[]'::jsonb)
    );

    ------------------------------------------------------------------
    -- 8-و) جریمه edgeهای مسیر برای مسیر جایگزین
    ------------------------------------------------------------------
    ------------------------------------------------------------------
-- 8-و) جریمه edgeهای مسیر برای مسیر جایگزین
-- اصلاح مهم:
-- edgeهای ابتدا و انتهای مسیر جریمه نمی‌شوند
-- تا مسیر جایگزین مجبور نشود از همان گام اول/آخر بدشکل شود.
------------------------------------------------------------------
WITH ordered AS (
  SELECT
    oe.edge_id,
    row_number() OVER (ORDER BY oe.seq) AS rn,
    count(*)    OVER () AS cnt
  FROM tmp_ordered_edges oe
  WHERE oe.edge_id IS NOT NULL
    AND oe.edge_id > 0
),

classified AS (
  SELECT
    edge_id,
    rn,
    cnt,
    CASE
      WHEN cnt <= 6 THEN
        -- مسیرهای خیلی کوتاه: فقط اگر edge میانی واقعی داریم جریمه کن
        CASE
          WHEN rn IN (1, cnt) THEN 'anchor'
          ELSE 'middle'
        END

      WHEN rn <= CEIL(cnt * 0.10) THEN 'anchor_start'
      WHEN rn >  FLOOR(cnt * 0.90) THEN 'anchor_end'
      ELSE 'middle'
    END AS part
  FROM ordered
)

UPDATE tmp_edges_live te
SET penalty_w =
  CASE
    -- فقط بدنه مسیر اصلی شدیداً جریمه شود
    WHEN c.part = 'middle' THEN te.penalty_w * 50.0

    -- ابتدا و انتها تقریباً آزاد بمانند
    -- این عدد را عمداً 1.0 گذاشتم، یعنی بدون جریمه
    ELSE te.penalty_w * 1.0
  END
FROM classified c
WHERE te.edge_id = c.edge_id;

  END LOOP;

  --------------------------------------------------------------------
  -- 9) خروجی
  --------------------------------------------------------------------
  RETURN QUERY
  SELECT
    o.path_rank,
    o.line_geom AS geom,
    o.line_cost AS cost,
    COALESCE(o.path_edges, '[]'::jsonb) AS path_edges
  FROM tmp_paths_out o
  ORDER BY o.path_rank;

END;
$_$;


--
-- Name: fn_route_walk_pgr_k_copy1(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_walk_pgr_k_copy1(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry, p_k integer DEFAULT 3) RETURNS TABLE(path_rank integer, geom public.geometry, cost numeric, path_edges jsonb)
    LANGUAGE plpgsql
    AS $_$
DECLARE
  v_origin_virtual bigint := -1000000001;
  v_dest_virtual   bigint := -1000000002;

  v_origin_area_id bigint;
  v_dest_area_id   bigint;

  v_i int;
  v_k int := LEAST(GREATEST(COALESCE(p_k, 1), 1), 10);

  v_sql text;
  v_has_path boolean;

  v_line_geom geometry(LineString, 32640);
  v_node_line geometry(LineString, 32640);
  v_line_cost numeric;
  v_path_edges jsonb;

  v_candidate_limit integer := 20;
  v_candidate_radius_m double precision := 200.0;
  v_clearance_m double precision := 0.20;

  v_start_connector_count integer := 0;
  v_dest_connector_count integer := 0;
BEGIN
  --------------------------------------------------------------------
  -- 0) نرمال‌سازی SRID
  --------------------------------------------------------------------
  IF p_origin IS NULL OR p_dest IS NULL THEN
    RETURN;
  END IF;

  IF ST_SRID(p_origin) <> 32640 THEN
    p_origin := ST_Transform(p_origin, 32640);
  END IF;

  IF ST_SRID(p_dest) <> 32640 THEN
    p_dest := ST_Transform(p_dest, 32640);
  END IF;

  --------------------------------------------------------------------
  -- 1) تشخیص area مبدأ و مقصد از allowed areas
  --------------------------------------------------------------------
  v_origin_area_id := public.fn_route_point_area_id(
    p_now,
    p_gender,
    p_mode,
    p_floor,
    p_origin
  );

  v_dest_area_id := public.fn_route_point_area_id(
    p_now,
    p_gender,
    p_mode,
    p_floor,
    p_dest
  );

  IF v_origin_area_id IS NULL OR v_dest_area_id IS NULL THEN
    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 2) edgeهای live اصلی
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_edges_live;

  CREATE TEMP TABLE tmp_edges_live AS
  SELECT
    e.id::bigint AS edge_id,
    e.src::bigint AS source,
    e.dst::bigint AS target,
    e.cost::float8 AS cost,
    e.reverse_cost::float8 AS reverse_cost,
    e.geom::geometry(LineString, 32640) AS edge_geom,
    e.door_id::bigint AS door_id,
    1.0::float8 AS penalty_w,
    'graph'::text AS edge_type
  FROM public.fn_routing_edges_live_param(
    p_now,
    p_gender,
    p_mode,
    p_floor
  ) e
  WHERE e.cost > 0
    AND e.geom IS NOT NULL
    AND NOT ST_IsEmpty(e.geom);

  CREATE INDEX ON tmp_edges_live(edge_id);
  CREATE INDEX ON tmp_edges_live(source);
  CREATE INDEX ON tmp_edges_live(target);

  --------------------------------------------------------------------
  -- 3) نودهای live
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_live_nodes;

  CREATE TEMP TABLE tmp_live_nodes AS
  SELECT DISTINCT source AS node_id
  FROM tmp_edges_live

  UNION

  SELECT DISTINCT target AS node_id
  FROM tmp_edges_live;

  CREATE INDEX ON tmp_live_nodes(node_id);

  --------------------------------------------------------------------
  -- 4) اتصال‌های مجازی مبدأ
  -- نکته: دیگر nearest-node تک‌گزینه‌ای نداریم.
  --------------------------------------------------------------------
  WITH start_candidates AS (
    SELECT
      rn.id AS node_id,
      rn.geom::geometry(Point, 32640) AS node_geom,
      rn.area_id,
      ST_Distance(p_origin, rn.geom) AS dist_m,
      public.fn_build_intra_area_edge_geom(
        rn.area_id,
        p_floor,
        p_origin,
        rn.geom,
        v_clearance_m
      ) AS connector_geom
    FROM public.routing_nodes rn
    JOIN tmp_live_nodes ln
      ON ln.node_id = rn.id
    WHERE rn.floor = p_floor
      AND rn.area_id = v_origin_area_id
      AND rn.ref_table = 'door_access_points'
      AND ST_DWithin(p_origin, rn.geom, v_candidate_radius_m)
    ORDER BY p_origin <-> rn.geom
    LIMIT v_candidate_limit
  ),
  valid_start AS (
    SELECT
      sc.*,
      ST_Length(sc.connector_geom) AS len_m,
      public.fn_heading_penalty(
        p_origin,
        sc.node_geom,
        p_dest,
        35.0
      ) AS heading_penalty
    FROM start_candidates sc
    WHERE sc.connector_geom IS NOT NULL
      AND NOT ST_IsEmpty(sc.connector_geom)
      AND public.fn_route_line_valid_inside_area(
        sc.area_id,
        sc.connector_geom,
        v_clearance_m
      )
  )
  INSERT INTO tmp_edges_live(
    edge_id,
    source,
    target,
    cost,
    reverse_cost,
    edge_geom,
    door_id,
    penalty_w,
    edge_type
  )
  SELECT
    (-2000000000 - row_number() OVER ())::bigint AS edge_id,
    v_origin_virtual AS source,
    vs.node_id AS target,
    GREATEST((vs.len_m + vs.heading_penalty)::float8, 0.01) AS cost,
    GREATEST((vs.len_m + vs.heading_penalty)::float8, 0.01) AS reverse_cost,
    vs.connector_geom::geometry(LineString, 32640) AS edge_geom,
    NULL::bigint AS door_id,
    1.0::float8 AS penalty_w,
    'origin_connector'::text AS edge_type
  FROM valid_start vs;

  GET DIAGNOSTICS v_start_connector_count = ROW_COUNT;

  --------------------------------------------------------------------
  -- 5) اتصال‌های مجازی مقصد
  --------------------------------------------------------------------
  WITH dest_candidates AS (
    SELECT
      rn.id AS node_id,
      rn.geom::geometry(Point, 32640) AS node_geom,
      rn.area_id,
      ST_Distance(p_dest, rn.geom) AS dist_m,
      public.fn_build_intra_area_edge_geom(
        rn.area_id,
        p_floor,
        rn.geom,
        p_dest,
        v_clearance_m
      ) AS connector_geom
    FROM public.routing_nodes rn
    JOIN tmp_live_nodes ln
      ON ln.node_id = rn.id
    WHERE rn.floor = p_floor
      AND rn.area_id = v_dest_area_id
      AND rn.ref_table = 'door_access_points'
      AND ST_DWithin(p_dest, rn.geom, v_candidate_radius_m)
    ORDER BY p_dest <-> rn.geom
    LIMIT v_candidate_limit
  ),
  valid_dest AS (
    SELECT
      dc.*,
      ST_Length(dc.connector_geom) AS len_m
    FROM dest_candidates dc
    WHERE dc.connector_geom IS NOT NULL
      AND NOT ST_IsEmpty(dc.connector_geom)
      AND public.fn_route_line_valid_inside_area(
        dc.area_id,
        dc.connector_geom,
        v_clearance_m
      )
  )
  INSERT INTO tmp_edges_live(
    edge_id,
    source,
    target,
    cost,
    reverse_cost,
    edge_geom,
    door_id,
    penalty_w,
    edge_type
  )
  SELECT
    (-2100000000 - row_number() OVER ())::bigint AS edge_id,
    vd.node_id AS source,
    v_dest_virtual AS target,
    GREATEST(vd.len_m::float8, 0.01) AS cost,
    GREATEST(vd.len_m::float8, 0.01) AS reverse_cost,
    vd.connector_geom::geometry(LineString, 32640) AS edge_geom,
    NULL::bigint AS door_id,
    1.0::float8 AS penalty_w,
    'dest_connector'::text AS edge_type
  FROM valid_dest vd;

  GET DIAGNOSTICS v_dest_connector_count = ROW_COUNT;

  --------------------------------------------------------------------
  -- 6) اگر اتصال مجازی ساخته نشد، مسیر نداریم
  --------------------------------------------------------------------
  IF v_start_connector_count = 0 OR v_dest_connector_count = 0 THEN
    RETURN;
  END IF;

  --------------------------------------------------------------------
  -- 7) خروجی مسیرها
  --------------------------------------------------------------------
  DROP TABLE IF EXISTS tmp_paths_out;

  CREATE TEMP TABLE tmp_paths_out (
    path_rank integer,
    line_geom geometry(LineString, 32640),
    line_cost numeric,
    path_edges jsonb
  ) ON COMMIT DROP;

  --------------------------------------------------------------------
  -- 8) تولید K مسیر
  --------------------------------------------------------------------
  FOR v_i IN 1..v_k LOOP

    v_sql := $q$
      SELECT
        edge_id::bigint AS id,
        source::bigint AS source,
        target::bigint AS target,
        (cost * penalty_w)::float8 AS cost,
        CASE
          WHEN reverse_cost < 0 THEN -1::float8
          ELSE (reverse_cost * penalty_w)::float8
        END AS reverse_cost
      FROM tmp_edges_live
    $q$;

    DROP TABLE IF EXISTS tmp_route_raw;

    CREATE TEMP TABLE tmp_route_raw AS
    SELECT *
    FROM pgr_dijkstra(
      v_sql,
      v_origin_virtual,
      v_dest_virtual,
      directed := true
    )
    WHERE edge <> -1;

    SELECT EXISTS (SELECT 1 FROM tmp_route_raw)
    INTO v_has_path;

    IF NOT v_has_path THEN
      EXIT;
    END IF;

    ------------------------------------------------------------------
    -- 8-الف) edgeهای مسیر با جهت درست
    ------------------------------------------------------------------
    DROP TABLE IF EXISTS tmp_ordered_edges;

    CREATE TEMP TABLE tmp_ordered_edges AS
    SELECT
      rr.seq,
      rr.path_seq,
      rr.node::bigint AS from_node,
      rr.edge::bigint AS edge_id,

      CASE
        WHEN te.source = rr.node THEN te.target
        ELSE te.source
      END::bigint AS to_node,

      te.door_id,
      te.edge_type,

      CASE
        WHEN te.source = rr.node THEN te.edge_geom
        ELSE ST_Reverse(te.edge_geom)
      END::geometry(LineString, 32640) AS edge_geom,

      rr.cost::numeric AS edge_cost
    FROM tmp_route_raw rr
    JOIN tmp_edges_live te
      ON te.edge_id = rr.edge
    ORDER BY rr.path_seq;

    ------------------------------------------------------------------
    -- 8-ب) path_edges برای stepها
    ------------------------------------------------------------------
    SELECT COALESCE(
      jsonb_agg(
        jsonb_build_object(
          'seq', path_seq,
          'edgeId', edge_id,
          'fromNode', from_node,
          'toNode', to_node,
          'doorId', door_id,
          'edgeType', edge_type
        )
        ORDER BY path_seq
      ),
      '[]'::jsonb
    )
    INTO v_path_edges
    FROM tmp_ordered_edges;

    ------------------------------------------------------------------
    -- 8-ج) هزینه مسیر
    ------------------------------------------------------------------
    SELECT COALESCE(SUM(edge_cost), 0)::numeric
    INTO v_line_cost
    FROM tmp_ordered_edges;

    ------------------------------------------------------------------
    -- 8-د) ساخت هندسه از edge.geom
    ------------------------------------------------------------------
    WITH edge_points AS (
      SELECT
        oe.path_seq,
        dp.path[1] AS pt_idx,
        dp.geom::geometry(Point, 32640) AS pt
      FROM tmp_ordered_edges oe
      CROSS JOIN LATERAL ST_DumpPoints(oe.edge_geom) AS dp
    ),
    filtered_points AS (
      SELECT
        ep.path_seq,
        ep.pt_idx,
        ep.pt
      FROM edge_points ep
      WHERE ep.path_seq = 1
         OR ep.pt_idx > 1
    )
    SELECT
      ST_RemoveRepeatedPoints(
        ST_MakeLine(fp.pt ORDER BY fp.path_seq, fp.pt_idx),
        0.01
      )::geometry(LineString, 32640)
    INTO v_line_geom
    FROM filtered_points fp;

    ------------------------------------------------------------------
    -- 8-هـ) fallback هندسی از nodeها اگر edge-geom خراب شد
    ------------------------------------------------------------------
    IF v_line_geom IS NULL
       OR ST_IsEmpty(v_line_geom)
       OR ST_NPoints(v_line_geom) < 2
    THEN
      WITH route_nodes AS (
        SELECT
          rr.seq,
          CASE
            WHEN rr.node = v_origin_virtual THEN p_origin::geometry(Point, 32640)
            WHEN rr.node = v_dest_virtual THEN p_dest::geometry(Point, 32640)
            ELSE rn.geom::geometry(Point, 32640)
          END AS pt
        FROM tmp_route_raw rr
        LEFT JOIN public.routing_nodes rn
          ON rn.id = rr.node
        ORDER BY rr.seq
      )
      SELECT
        ST_RemoveRepeatedPoints(
          ST_MakeLine(rn.pt ORDER BY rn.seq),
          0.01
        )::geometry(LineString, 32640)
      INTO v_node_line
      FROM route_nodes rn
      WHERE rn.pt IS NOT NULL;

      v_line_geom := v_node_line;
    END IF;

    IF v_line_geom IS NULL
       OR ST_IsEmpty(v_line_geom)
       OR ST_NPoints(v_line_geom) < 2
    THEN
      EXIT;
    END IF;

    INSERT INTO tmp_paths_out(
      path_rank,
      line_geom,
      line_cost,
      path_edges
    )
    VALUES (
      v_i,
      v_line_geom,
      COALESCE(v_line_cost, ST_Length(v_line_geom)::numeric),
      COALESCE(v_path_edges, '[]'::jsonb)
    );

    ------------------------------------------------------------------
    -- 8-و) جریمه edgeهای مسیر برای مسیر جایگزین
    ------------------------------------------------------------------
    UPDATE tmp_edges_live te
    SET penalty_w = penalty_w * 50.0
    WHERE te.edge_id IN (
      SELECT edge_id
      FROM tmp_ordered_edges
    );

  END LOOP;

  --------------------------------------------------------------------
  -- 9) خروجی
  --------------------------------------------------------------------
  RETURN QUERY
  SELECT
    o.path_rank,
    o.line_geom AS geom,
    o.line_cost AS cost,
    COALESCE(o.path_edges, '[]'::jsonb) AS path_edges
  FROM tmp_paths_out o
  ORDER BY o.path_rank;

END;
$_$;


--
-- Name: fn_route_walk_visibility(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_walk_visibility(p_ts timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry) RETURNS TABLE(seq integer, geom public.geometry, mode text, floor smallint, distance_m numeric, duration_s numeric, meta jsonb)
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_speed_mps numeric := 0.8;

  g_walk      geometry;               -- MultiPolygon, 32640
  v_line      geometry(LineString, 32640);
  v_is_visible boolean;

  -- areaهای مبدا و مقصد
  v_origin_area_id bigint;
  v_dest_area_id   bigint;

  -- خروجی از fn_route_walk_pgr
  v_seq        integer;
  v_geom_raw   geometry;
  v_cost       numeric;

  -- متغیرهای اتصال مبدا/مقصد به مسیر pgr (همان نسخهٔ قبلی با LineLocatePoint و ...)
  v_geom_ls      geometry(LineString, 32640);
  v_m_o          double precision;
  v_m_d          double precision;
  v_cp_o         geometry(Point, 32640);
  v_cp_d         geometry(Point, 32640);
  v_m_start      double precision;
  v_m_end        double precision;
  v_cp_start     geometry(Point, 32640);
  v_cp_end       geometry(Point, 32640);
  v_path_sub     geometry(LineString, 32640);
  v_geom_full    geometry(LineString, 32640);
  v_tmp_geom     geometry;
BEGIN
  --------------------------------------------------------------------
  -- 0) پیدا کردن area مبدا و مقصد
  --------------------------------------------------------------------
  SELECT id INTO v_origin_area_id
  FROM areas a
  WHERE a.floor = p_floor
    AND ST_Contains(a.geom, p_origin)
  LIMIT 1;

  SELECT id INTO v_dest_area_id
  FROM areas b
  WHERE b.floor = p_floor
    AND ST_Contains(b.geom, p_dest)
  LIMIT 1;

  --------------------------------------------------------------------
  -- 1) هندسهٔ قابل عبور زنده
  --------------------------------------------------------------------
  g_walk := fn_walkable_geom_live(p_ts, p_gender, p_mode, p_floor);

  --------------------------------------------------------------------
  -- 2) مسیر مستقیم فقط اگر هر دو در یک area باشند
  --------------------------------------------------------------------
  IF g_walk IS NOT NULL
     AND v_origin_area_id IS NOT NULL
     AND v_origin_area_id = v_dest_area_id
  THEN
    v_line := ST_MakeLine(p_origin, p_dest);
    IF ST_SRID(v_line) IS DISTINCT FROM 32640 THEN
      v_line := ST_SetSRID(v_line, 32640);
    END IF;

    v_is_visible := ST_Covers(g_walk, ST_SnapToGrid(v_line, 0.01));

    IF v_is_visible THEN
      seq        := 1;
      geom       := v_line;
      mode       := p_mode;
      floor      := p_floor;
      distance_m := ST_Length(v_line);
      duration_s := distance_m / v_speed_mps;
      meta       := jsonb_build_object(
                      'type',        'direct_visibility_same_area',
                      'gender',      p_gender,
                      'mode',        p_mode,
                      'floor',       p_floor,
                      'fallback',    false,
                      'area_id',     v_origin_area_id
                    );
      RETURN NEXT;
      RETURN;
    END IF;
  END IF;

  --------------------------------------------------------------------
  -- 3) در غیر این صورت فقط سراغ گراف می‌رویم
  --------------------------------------------------------------------
  FOR v_seq, v_geom_raw, v_cost IN
    SELECT r.seq, r.geom, r.cost
    FROM fn_route_walk_pgr(
           p_ts,
           p_gender,
           p_mode,
           p_floor,
           p_origin,
           p_dest
         ) AS r
  LOOP
    IF v_geom_raw IS NULL THEN
      CONTINUE;
    END IF;

    -- (همان منطق قبلی LineLocatePoint / LineSubstring و اتصال مبدا/مقصد)
    v_geom_ls := ST_LineMerge(v_geom_raw)::geometry(LineString, 32640);
    IF ST_SRID(v_geom_ls) IS DISTINCT FROM 32640 THEN
      v_geom_ls := ST_Transform(v_geom_ls, 32640);
    END IF;

    v_m_o  := ST_LineLocatePoint(v_geom_ls, p_origin);
    v_cp_o := ST_LineInterpolatePoint(v_geom_ls, v_m_o)::geometry(Point, 32640);

    v_m_d  := ST_LineLocatePoint(v_geom_ls, p_dest);
    v_cp_d := ST_LineInterpolatePoint(v_geom_ls, v_m_d)::geometry(Point, 32640);

    IF v_m_o <= v_m_d THEN
      v_m_start  := v_m_o;
      v_m_end    := v_m_d;
      v_cp_start := v_cp_o;
      v_cp_end   := v_cp_d;
    ELSE
      v_m_start  := v_m_d;
      v_m_end    := v_m_o;
      v_cp_start := v_cp_d;
      v_cp_end   := v_cp_o;
    END IF;

    v_path_sub := ST_LineSubstring(v_geom_ls, v_m_start, v_m_end)::geometry(LineString, 32640);

    v_tmp_geom := v_path_sub;
    IF NOT ST_DWithin(p_origin, v_cp_start, 0.05) THEN
      v_tmp_geom :=
        ST_LineMerge(
          ST_Collect(
            ST_MakeLine(p_origin, v_cp_start),
            v_tmp_geom
          )
        )::geometry(LineString, 32640);
    END IF;

    IF NOT ST_DWithin(p_dest, v_cp_end, 0.05) THEN
      v_tmp_geom :=
        ST_LineMerge(
          ST_Collect(
            v_tmp_geom,
            ST_MakeLine(v_cp_end, p_dest)
          )
        )::geometry(LineString, 32640);
    END IF;

    v_geom_full := v_tmp_geom;

    seq        := v_seq;
    geom       := v_geom_full;
    mode       := p_mode;
    floor      := p_floor;
    distance_m := ST_Length(v_geom_full);
    duration_s := distance_m / v_speed_mps;
    meta       := jsonb_build_object(
                    'type',        'pgr_fallback',
                    'gender',      p_gender,
                    'mode',        p_mode,
                    'floor',       p_floor,
                    'fallback',    true
                  );
    RETURN NEXT;
  END LOOP;

  -- اگر گراف هم مسیری نداد، هیچ خط مستقیمی ساخته نمی‌شود → NO_PATH
  RETURN;
END;
$$;


--
-- Name: fn_route_walk_visibilitySelf(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public."fn_route_walk_visibilitySelf"(p_ts timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry) RETURNS TABLE(seq integer, geom public.geometry, mode text, floor smallint, distance_m numeric, duration_s numeric, meta jsonb)
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_origin_node bigint;
  v_dest_node   bigint;
  v_speed_mps   numeric := 1.4;

  g_walk        geometry;

  v_curr_node   bigint;
  v_curr_cost   numeric;
  v_new_cost    numeric;
  v_old_cost    numeric;
  v_iter        integer := 0;
  v_max_iter    integer := 50000;
  v_check_node  bigint;
  v_ord         integer;

  r_edge        record;
  v_edges_total bigint;
  v_deg_origin  bigint;
  v_deg_dest    bigint;
BEGIN
  ------------------------------------------------------------
  -- 1) نودهای موقت مبدأ و مقصد
  ------------------------------------------------------------
  INSERT INTO routing_nodes (floor, geom, kind)
  VALUES (p_floor, p_origin, 'origin')
  RETURNING id INTO v_origin_node;

  INSERT INTO routing_nodes (floor, geom, kind)
  VALUES (p_floor, p_dest, 'destination')
  RETURNING id INTO v_dest_node;

  ------------------------------------------------------------
  -- 2) walkable زنده (با توجه به زمان/جنسیت/mode و موانع موقت)
  ------------------------------------------------------------
  SELECT fn_walkable_geom_live(
           p_ts,
           p_gender,
           p_mode,
           p_floor
         )
  INTO g_walk;

  IF g_walk IS NULL THEN
    RAISE NOTICE 'No walkable area on floor %', p_floor;
    DELETE FROM routing_nodes WHERE id IN (v_origin_node, v_dest_node);
    RETURN;
  END IF;

  ------------------------------------------------------------
  -- 3) گراف موقت
  ------------------------------------------------------------
  CREATE TEMP TABLE tmp_edges (
    src    bigint,
    dst    bigint,
    weight numeric
  ) ON COMMIT DROP;

  -- 3-الف) یال‌های موجود گراف زنده (از قبل محاسبه‌شده)
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT
    rel.src,
    rel.dst,
    rel.cost::numeric
  FROM routing_edges_live rel
  WHERE rel.floor = p_floor;

  -- دوطرفه کردن یال‌ها
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT dst, src, weight
  FROM tmp_edges;

  -- 3-ب) اتصال مبدأ به نزدیک‌ترین نودها (فقط داخل g_walk)
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT
    v_origin_node AS src,
    n.id          AS dst,
    ST_Length(ST_MakeLine(p_origin, n.geom))::numeric AS weight
  FROM routing_nodes n
  WHERE n.floor = p_floor
    AND n.id NOT IN (v_origin_node, v_dest_node)
    AND ST_DWithin(p_origin, n.geom, 40)                    -- شعاع منطقی
    AND ST_Covers(g_walk, ST_MakeLine(p_origin, n.geom));   -- خط کامل داخل ناحیه مجاز

  -- 3-ج) اتصال مقصد
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT
    v_dest_node AS src,
    n.id        AS dst,
    ST_Length(ST_MakeLine(p_dest, n.geom))::numeric AS weight
  FROM routing_nodes n
  WHERE n.floor = p_floor
    AND n.id NOT IN (v_origin_node, v_dest_node)
    AND ST_DWithin(p_dest, n.geom, 40)
    AND ST_Covers(g_walk, ST_MakeLine(p_dest, n.geom));

  -- دوطرفه کردن یال‌های origin/dest
  INSERT INTO tmp_edges (src, dst, weight)
  SELECT dst, src, weight
  FROM tmp_edges
  WHERE src IN (v_origin_node, v_dest_node);

  -- لاگ برای دیباگ (در صورت نیاز)
  SELECT count(*) INTO v_edges_total FROM tmp_edges;
  SELECT count(*) INTO v_deg_origin FROM tmp_edges WHERE src = v_origin_node;
  SELECT count(*) INTO v_deg_dest   FROM tmp_edges WHERE src = v_dest_node;
  RAISE NOTICE 'tmp_edges total: %, origin_deg: %, dest_deg: %',
    v_edges_total, v_deg_origin, v_deg_dest;

  CREATE INDEX ON tmp_edges (src);

  ------------------------------------------------------------
  -- 4) Dijkstra
  ------------------------------------------------------------
  CREATE TEMP TABLE tmp_dijkstra (
    node_id bigint PRIMARY KEY,
    cost    numeric,
    prev    bigint,
    visited boolean DEFAULT false
  ) ON COMMIT DROP;

  INSERT INTO tmp_dijkstra(node_id, cost, prev, visited)
  VALUES (v_origin_node, 0, NULL, false);

  CREATE INDEX ON tmp_dijkstra (visited, cost);

  LOOP
    v_iter := v_iter + 1;
    IF v_iter > v_max_iter THEN
      RAISE NOTICE 'Dijkstra max_iter reached';
      EXIT;
    END IF;

    SELECT node_id, cost
    INTO v_curr_node, v_curr_cost
    FROM tmp_dijkstra
    WHERE visited = false
    ORDER BY cost
    LIMIT 1;

    IF NOT FOUND THEN
      EXIT;
    END IF;

    IF v_curr_node = v_dest_node THEN
      EXIT;
    END IF;

    UPDATE tmp_dijkstra
    SET visited = true
    WHERE node_id = v_curr_node;

    FOR r_edge IN
      SELECT dst, weight
      FROM tmp_edges
      WHERE src = v_curr_node
    LOOP
      v_new_cost := v_curr_cost + r_edge.weight;

      SELECT cost
      INTO v_old_cost
      FROM tmp_dijkstra
      WHERE node_id = r_edge.dst;

      IF NOT FOUND THEN
        INSERT INTO tmp_dijkstra(node_id, cost, prev, visited)
        VALUES (r_edge.dst, v_new_cost, v_curr_node, false);
      ELSIF v_old_cost > v_new_cost THEN
        UPDATE tmp_dijkstra
        SET cost = v_new_cost,
            prev = v_curr_node,
            visited = false
        WHERE node_id = r_edge.dst;
      END IF;
    END LOOP;
  END LOOP;

  ------------------------------------------------------------
  -- 5) بازسازی مسیر
  ------------------------------------------------------------
  CREATE TEMP TABLE tmp_path_nodes (
    ord     integer,
    node_id bigint
  ) ON COMMIT DROP;

  v_curr_node := v_dest_node;
  v_ord := 0;

  WHILE v_curr_node IS NOT NULL LOOP
    INSERT INTO tmp_path_nodes(ord, node_id)
    VALUES (v_ord, v_curr_node);

    SELECT prev
    INTO v_curr_node
    FROM tmp_dijkstra
    WHERE node_id = v_curr_node;

    v_ord := v_ord + 1;
    IF v_ord > 100000 THEN
      RAISE NOTICE 'Path reconstruction overflow';
      EXIT;
    END IF;
  END LOOP;

  SELECT node_id
  INTO v_check_node
  FROM tmp_path_nodes
  ORDER BY ord DESC
  LIMIT 1;

  IF v_check_node IS NULL OR v_check_node <> v_origin_node THEN
    RAISE NOTICE 'No path found between origin and dest on floor %', p_floor;
    DELETE FROM routing_nodes WHERE id IN (v_origin_node, v_dest_node);
    RETURN;
  END IF;

  ------------------------------------------------------------
  -- 6) ساخت LineString نهایی
  ------------------------------------------------------------
  RETURN QUERY
  WITH ordered AS (
    SELECT node_id, ord
    FROM tmp_path_nodes
    ORDER BY ord DESC
  ),
  pts AS (
    SELECT rn.geom AS pt_geom, o.ord
    FROM ordered o
    JOIN routing_nodes rn ON rn.id = o.node_id
    ORDER BY o.ord
  ),
  route AS (
    SELECT
      ST_SetSRID(
        ST_MakeLine(pt_geom ORDER BY ord),
        32640
      )::geometry(LineString,32640) AS geom
    FROM pts
  )
  SELECT
    1 AS seq,
    r.geom,
    p_mode::text AS mode,
    p_floor      AS floor,
    ST_Length(r.geom)::numeric AS distance_m,
    (ST_Length(r.geom) / v_speed_mps)::numeric AS duration_s,
    jsonb_build_object(
      'gender', p_gender,
      'mode',   p_mode,
      'floor',  p_floor
    ) AS meta
  FROM route r;

  ------------------------------------------------------------
  -- 7) پاک‌کردن نودهای موقت
  ------------------------------------------------------------
  DELETE FROM routing_nodes WHERE id IN (v_origin_node, v_dest_node);
END;
$$;


--
-- Name: fn_route_walk_visibility_k(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_walk_visibility_k(p_ts timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry, p_k integer DEFAULT 3) RETURNS TABLE(path_rank integer, geom public.geometry, mode text, floor smallint, distance_m numeric, duration_s numeric, meta jsonb)
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_speed_mps numeric := 0.8;

  g_walk      geometry;               -- MultiPolygon, 32640
  v_line      geometry(LineString, 32640);
  v_is_visible boolean;

  -- areaهای مبدا و مقصد
  v_origin_area_id bigint;
  v_dest_area_id   bigint;

  -- خروجی از fn_route_walk_pgr_k
  v_path_rank integer;
  v_geom_raw  geometry;
  v_cost      numeric;

  -- متغیرهای اتصال مبدا/مقصد به مسیر pgr
  v_geom_ls      geometry(LineString, 32640);
  v_m_o          double precision;
  v_m_d          double precision;
  v_cp_o         geometry(Point, 32640);
  v_cp_d         geometry(Point, 32640);
  v_m_start      double precision;
  v_m_end        double precision;
  v_cp_start     geometry(Point, 32640);
  v_cp_end       geometry(Point, 32640);
  v_path_sub     geometry(LineString, 32640);
  v_geom_full    geometry(LineString, 32640);
  v_tmp_geom     geometry;

  v_direct_emitted boolean := false;
  v_offset integer := 0;
	
	v_path_edges jsonb;
BEGIN
  --------------------------------------------------------------------
  -- 0) پیدا کردن area مبدا و مقصد
  --------------------------------------------------------------------
  SELECT id INTO v_origin_area_id
  FROM areas a
  WHERE a.floor = p_floor
    AND a.geom && p_origin
    AND ST_Contains(a.geom, p_origin)
  LIMIT 1;

  SELECT id INTO v_dest_area_id
  FROM areas b
  WHERE b.floor = p_floor
    AND ST_Contains(b.geom, p_dest)
  LIMIT 1;

  --------------------------------------------------------------------
  -- 1) هندسهٔ قابل عبور زنده
  --------------------------------------------------------------------
  g_walk := fn_walkable_geom_cached(p_ts, p_gender, p_mode, p_floor);

  --------------------------------------------------------------------
  -- 2) مسیر مستقیم فقط اگر هر دو در یک area باشند
  --    تغییر: اگر p_k>1 بود، RETURN زودهنگام حذف می‌شود
  --------------------------------------------------------------------
  IF false
   AND g_walk IS NOT NULL
   AND v_origin_area_id IS NOT NULL
   AND v_origin_area_id = v_dest_area_id
THEN
    v_line := ST_MakeLine(p_origin, p_dest);
    IF ST_SRID(v_line) IS DISTINCT FROM 32640 THEN
      v_line := ST_SetSRID(v_line, 32640);
    END IF;

    v_is_visible := ST_Covers(g_walk, ST_SnapToGrid(v_line, 0.01));

    IF v_is_visible THEN
      path_rank  := 1;
      geom       := v_line;
      mode       := p_mode;
      floor      := p_floor;
      distance_m := ST_Length(v_line);
      duration_s := distance_m / v_speed_mps;
      meta       := jsonb_build_object(
                      'type',        'direct_visibility_same_area',
                      'gender',      p_gender,
                      'mode',        p_mode,
                      'floor',       p_floor,
                      'fallback',    false,
                      'area_id',     v_origin_area_id,
											'path_edges', '[]'::jsonb
                    );
      RETURN NEXT;

      v_direct_emitted := true;
      v_offset := 1;

      -- اگر فقط 1 مسیر می‌خواستیم، همینجا تمام
      IF COALESCE(p_k,1) <= 1 THEN
        RETURN;
      END IF;
    END IF;
  END IF;

  --------------------------------------------------------------------
-- 3) مسیرهای گراف pgr
-- نکته مهم:
-- هندسه‌ای که از fn_route_walk_pgr_k می‌آید، خودش از edge.geom ساخته شده.
-- پس اینجا نباید دوباره ST_LineSubstring / ST_MakeLine روی نودها انجام شود.
--------------------------------------------------------------------
FOR v_path_rank, v_geom_raw, v_cost, v_path_edges IN
  SELECT
    r.path_rank,
    r.geom,
    r.cost,
    COALESCE(r.path_edges, '[]'::jsonb) AS path_edges
  FROM public.fn_route_walk_pgr_k(
         p_ts,
         p_gender,
         p_mode,
         p_floor,
         p_origin,
         p_dest,
         LEAST(GREATEST(p_k, 1), 10)
       ) AS r
LOOP
  IF v_geom_raw IS NULL OR ST_IsEmpty(v_geom_raw) THEN
    CONTINUE;
  END IF;

  v_geom_full := ST_LineMerge(v_geom_raw)::geometry(LineString, 32640);

  IF ST_SRID(v_geom_full) IS DISTINCT FROM 32640 THEN
    v_geom_full := ST_Transform(v_geom_full, 32640);
  END IF;

  path_rank  := v_path_rank + v_offset;
  geom       := v_geom_full;
  mode       := p_mode;
  floor      := p_floor;
  distance_m := ST_Length(v_geom_full);
  duration_s := distance_m / v_speed_mps;

  meta       := jsonb_build_object(
                  'type',        'pgr_edge_geom',
                  'gender',      p_gender,
                  'mode',        p_mode,
                  'floor',       p_floor,
                  'fallback',    true,
                  'path_rank',   (v_path_rank + v_offset),
                  'base_cost',   v_cost,
                  'direct_emitted', v_direct_emitted,
                  'path_edges', COALESCE(v_path_edges, '[]'::jsonb)
                );

  RETURN NEXT;

  IF (v_path_rank + v_offset) >= COALESCE(p_k, 1) THEN
    EXIT;
  END IF;
END LOOP;

RETURN;
END;
$$;


--
-- Name: fn_route_walk_visibility_self_v1(timestamp with time zone, public.gender_enum, text, smallint, public.geometry, public.geometry); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_route_walk_visibility_self_v1(p_ts timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint, p_origin public.geometry, p_dest public.geometry) RETURNS TABLE(seq integer, geom public.geometry, mode text, floor smallint, distance_m numeric, duration_s numeric, meta jsonb)
    LANGUAGE plpgsql
    AS $$
DECLARE
  -- سرعت متوسط حرکت (متر بر ثانیه)
  v_speed_mps numeric := 1.4;

  -- هندسهٔ قابل‌عبور زنده (با در نظر گرفتن موانع موقت)
  g_walk      geometry;               -- MultiPolygon, 32640

  -- خط مستقیم بین مبدا و مقصد
  v_line      geometry(LineString, 32640);

  -- پرچم اینکه آیا مسیر مستقیم مجاز است یا نه
  v_is_visible boolean;

  -- خروجی از fn_route_walk_pgr
  v_seq        integer;
  v_geom       geometry;
  v_cost       numeric;
BEGIN
  --------------------------------------------------------------------
  -- 1) هندسهٔ قابل عبور زنده (walkable)
  --------------------------------------------------------------------
  g_walk := fn_walkable_geom_live(p_ts, p_gender, p_mode, p_floor);

  --------------------------------------------------------------------
  -- 2) تلاش برای مسیر مستقیم درون g_walk (visibility)
  --------------------------------------------------------------------
  IF g_walk IS NOT NULL THEN
    -- فرض بر این است که p_origin و p_dest در SRID=32640 هستند
    v_line := ST_MakeLine(p_origin, p_dest);
    IF ST_SRID(v_line) IS DISTINCT FROM 32640 THEN
      v_line := ST_SetSRID(v_line, 32640);
    END IF;

    -- اگر کل خط داخل ناحیه قابل عبور باشد، همان را برگردان
    -- از ST_Covers استفاده می‌کنیم تا لبه‌ها هم پذیرفته شوند
    v_is_visible := ST_Covers(g_walk, ST_SnapToGrid(v_line, 0.01));

    IF v_is_visible THEN
      seq        := 1;
      geom       := v_line;
      mode       := p_mode;
      floor      := p_floor;
      distance_m := ST_Length(v_line);
      duration_s := distance_m / v_speed_mps;
      meta       := jsonb_build_object(
                      'type',        'direct_visibility',
                      'gender',      p_gender,
                      'mode',        p_mode,
                      'floor',       p_floor,
                      'fallback',    false
                    );

      RETURN NEXT;
      RETURN;
    END IF;
  END IF;

  --------------------------------------------------------------------
  -- 3) Fallback: استفاده از fn_route_walk_pgr (pgRouting)
  --------------------------------------------------------------------
  FOR v_seq, v_geom, v_cost IN
    SELECT r.seq, r.geom, r.cost
    FROM fn_route_walk_pgr(
           p_ts,
           p_gender,
           p_mode,
           p_floor,
           p_origin,
           p_dest
         ) AS r
  LOOP
    -- در طراحی فعلی fn_route_walk_pgr عملاً یک Row با کل LineString برمی‌گرداند،
    -- ولی اینجا به صورت عمومی Loop نوشته شده است
    seq        := v_seq;
    geom       := v_geom;
    mode       := p_mode;
    floor      := p_floor;
    distance_m := v_cost;              -- در fn_route_walk_pgr، cost همان طول مسیر است
    duration_s := distance_m / v_speed_mps;
    meta       := jsonb_build_object(
                    'type',        'pgr_fallback',
                    'gender',      p_gender,
                    'mode',        p_mode,
                    'floor',       p_floor,
                    'fallback',    true
                  );

    RETURN NEXT;
  END LOOP;

  --------------------------------------------------------------------
  -- 4) اگر fn_route_walk_pgr هم چیزی برنگرداند، این تابع هم NO_PATH است
  -- (هیچ رکوردی برنمی‌گردد؛ هندل کردن پیام با سرویس API است)
  --------------------------------------------------------------------
  RETURN;
END;
$$;


--
-- Name: fn_routing_edges_live(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_routing_edges_live(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS TABLE(id bigint, source bigint, target bigint, cost double precision, geom public.geometry, door_id bigint)
    LANGUAGE sql
    AS $$
WITH
-- نواحی مجاز (طبق منطق فعلی شما)
allowed_areas AS (
  SELECT id, is_allowed
  FROM fn_allowed_areas(p_now, p_gender, p_mode, p_floor)
),

-- درب‌های مجاز + محاسبه is_open_effective (door_status_live روی doors override شود)
allowed_doors AS (
  SELECT
    d.id,
    COALESCE(dsl.is_open, d.is_open) AS is_open_effective,
    COALESCE(acc.allowed, TRUE)      AS is_allowed_by_time_prayer
  FROM doors d
  LEFT JOIN door_status_live dsl
    ON dsl.door_id = d.id
   AND dsl.mode = 'normal'
  LEFT JOIN LATERAL (
    SELECT a.allowed
    FROM fn_entity_access(
      'doors',
      d.id,
      p_now,
      p_gender,
      p_mode,
      p_floor,
      ST_LineInterpolatePoint(d.geom, 0.5)
    ) AS a
    LIMIT 1
  ) acc ON TRUE
  WHERE d.floor = p_floor
    AND (p_mode = ANY(d.modes))
),

tb AS (
  SELECT geom
  FROM v_temp_block_areas_active
  WHERE floor = p_floor
)

SELECT
  e.id,
  e.src AS source,
  e.dst AS target,
  (e.base_cost::double precision) AS cost,
  e.geom,
  e.door_id
FROM routing_edges_static e
JOIN routing_nodes ns ON ns.id = e.src
JOIN routing_nodes nt ON nt.id = e.dst

-- ✅ به جای JOIN سخت‌گیرانه، LEFT JOIN می‌کنیم
LEFT JOIN allowed_areas a1 ON a1.id = ns.area_id
LEFT JOIN allowed_areas a2 ON a2.id = nt.area_id

LEFT JOIN allowed_doors d ON d.id = e.door_id

WHERE e.floor = p_floor

  -- ✅ اگر area_id موجود است باید allowed باشد؛ اگر NULL است حذف نکن
  AND (ns.area_id IS NULL OR COALESCE(a1.is_allowed, TRUE) = TRUE)
  AND (nt.area_id IS NULL OR COALESCE(a2.is_allowed, TRUE) = TRUE)

  -- ✅ اصلاح کلیدی: اگر door_id دارد ولی join نشد => غیرمجاز
  AND (
    e.door_id IS NULL
    OR (
      d.id IS NOT NULL
      AND d.is_allowed_by_time_prayer = TRUE
      AND d.is_open_effective = TRUE
    )
  )

  -- temp blocks
  AND NOT EXISTS (
    SELECT 1 FROM tb
    WHERE ST_Intersects(e.geom, tb.geom)
  );
$$;


--
-- Name: fn_routing_edges_live_param(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_routing_edges_live_param(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS TABLE(id bigint, floor smallint, src bigint, dst bigint, geom public.geometry, base_cost numeric, cost numeric, reverse_cost numeric, door_id bigint)
    LANGUAGE sql STABLE
    AS $$

WITH

--------------------------------------------------------------
-- Areaهای مجاز
--------------------------------------------------------------
allowed_areas AS (

    SELECT

        fa.id,

        GREATEST(
            1.0,
            1.0 + COALESCE(
                fa.admin_penalty_w,
                0.0
            )
        )::numeric AS area_cost_w

    FROM public.fn_allowed_areas(
        p_now,
        p_gender,
        p_mode,
        p_floor
    ) fa

    WHERE fa.is_allowed = TRUE
),

--------------------------------------------------------------
-- Nodeهای مجاز
--
-- نکته:
-- ref_id برای node درب = door_access_points.id
--------------------------------------------------------------
allowed_nodes AS (

    SELECT

        rn.id,
        rn.area_id,
        rn.ref_table,
        rn.ref_id,

        aa.area_cost_w,

        ----------------------------------------------------------
        -- فقط از DAP به door_id می‌رسیم
        ----------------------------------------------------------
        dap.door_id AS node_door_id

    FROM public.routing_nodes rn

    JOIN allowed_areas aa
      ON aa.id = rn.area_id

    LEFT JOIN public.door_access_points dap

      ON rn.ref_table = 'door_access_points'

     AND rn.ref_id = dap.id

     AND rn.floor = dap.floor

     AND COALESCE(
         dap.needs_review,
         FALSE
     ) = FALSE

    WHERE

        rn.floor = p_floor

        AND (

            rn.ref_table <> 'door_access_points'

            OR dap.id IS NOT NULL
        )
),

--------------------------------------------------------------
-- Static edges
--
-- هندسه فقط routing_edges_static.geom
--------------------------------------------------------------
edges0 AS (

    SELECT

        e.id,
        e.floor,
        e.src,
        e.dst,
        e.geom,
        e.base_cost,
        e.door_id,

        COALESCE(
            e.attrs,
            '{}'::jsonb
        ) AS attrs,

        ns.area_id AS src_area_id,
        nd.area_id AS dst_area_id,

        ns.ref_table AS src_ref_table,
        ns.ref_id AS src_ref_id,

        nd.ref_table AS dst_ref_table,
        nd.ref_id AS dst_ref_id,

        ----------------------------------------------------------
        -- door_id استخراج‌شده از DAP
        ----------------------------------------------------------
        ns.node_door_id AS src_door_ref_id,
        nd.node_door_id AS dst_door_ref_id,

        ns.area_cost_w AS src_area_cost_w,
        nd.area_cost_w AS dst_area_cost_w

    FROM public.routing_edges_static e

    JOIN allowed_nodes ns
      ON ns.id = e.src

    JOIN allowed_nodes nd
      ON nd.id = e.dst

    WHERE

        e.floor = p_floor

        AND COALESCE(
                e.attrs ->> 'safety',
                ''
            ) <> 'unsafe_direct_fallback'

        AND COALESCE(
                e.attrs ->> 'valid_inside_area',
                'true'
            ) = 'true'
),

--------------------------------------------------------------
-- هزینه زنده edge
--------------------------------------------------------------
edge_doors AS (

    SELECT

        e0.*,

        (
            e0.base_cost

            * COALESCE(
                e0.src_area_cost_w,
                1.0
            )

            * COALESCE(
                e0.dst_area_cost_w,
                1.0
            )
        )::numeric AS live_cost

    FROM edges0 e0
),

--------------------------------------------------------------
-- تمام درب‌هایی که این مجموعه edge به آنها وابسته است
--------------------------------------------------------------
needed_doors AS (

    SELECT
        door_id AS door_ref_id

    FROM edge_doors

    WHERE door_id IS NOT NULL


    UNION


    SELECT
        src_door_ref_id

    FROM edge_doors

    WHERE src_door_ref_id IS NOT NULL


    UNION


    SELECT
        dst_door_ref_id

    FROM edge_doors

    WHERE dst_door_ref_id IS NOT NULL
),

--------------------------------------------------------------
-- وضعیت دسترسی درب
--
-- fn_allowed_doors حالا:
-- gender
-- mode
-- is_open
-- door_status_live
-- admin
-- time
-- prayer
-- را با هم بررسی می‌کند.
--------------------------------------------------------------
door_access AS (

    SELECT

        fd.id AS door_id,
        fd.is_allowed,
        fd.bidirectional

    FROM public.fn_allowed_doors(
        p_now,
        p_gender,
        p_mode,
        p_floor
    ) fd

    JOIN needed_doors nd
      ON nd.door_ref_id = fd.id
)

--------------------------------------------------------------
-- Final
--------------------------------------------------------------
SELECT

    e.id,
    e.floor,
    e.src,
    e.dst,

    --------------------------------------------------------------
    -- تنها هندسه routing
    --------------------------------------------------------------
    e.geom,

    e.base_cost,

    e.live_cost AS cost,

    CASE

        WHEN e.door_id IS NULL
            THEN e.live_cost

        WHEN COALESCE(
                 da_main.bidirectional,
                 TRUE
             ) = TRUE
            THEN e.live_cost

        ELSE (-1)::numeric

    END AS reverse_cost,

    e.door_id

FROM edge_doors e

LEFT JOIN door_access da_main
       ON da_main.door_id = e.door_id

LEFT JOIN door_access src_da
       ON src_da.door_id = e.src_door_ref_id

LEFT JOIN door_access dst_da
       ON dst_da.door_id = e.dst_door_ref_id

WHERE

    --------------------------------------------------------------
    -- اگر خود edge متعلق به door است
    --------------------------------------------------------------
    (
        e.door_id IS NULL

        OR COALESCE(
            da_main.is_allowed,
            FALSE
        ) = TRUE
    )

    --------------------------------------------------------------
    -- اگر src یک DAP متعلق به door است
    --------------------------------------------------------------
    AND (

        e.src_door_ref_id IS NULL

        OR COALESCE(
            src_da.is_allowed,
            FALSE
        ) = TRUE
    )

    --------------------------------------------------------------
    -- اگر dst یک DAP متعلق به door است
    --------------------------------------------------------------
    AND (

        e.dst_door_ref_id IS NULL

        OR COALESCE(
            dst_da.is_allowed,
            FALSE
        ) = TRUE
    );

$$;


--
-- Name: fn_routing_edges_static_mvt(integer, integer, integer, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_routing_edges_static_mvt(z integer, x integer, y integer, p_floor smallint DEFAULT NULL::smallint) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857  geometry;
  tile_bbox_32640 geometry;
BEGIN
  tile_bbox_3857  := ST_TileEnvelope(z, x, y);
  tile_bbox_32640 := ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT ST_AsMVT(t, 'routing_edges_static', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(e.geom, 3857),
          tile_bbox_3857,
          4096,
          64,
          true
        ) AS geom,

        e.id,
        e.floor,
        e.src,
        e.dst,
        e.base_cost,
        e.door_id,

        COALESCE(e.attrs->>'edge_type', 'intra_area') AS edge_type,
        COALESCE(e.attrs->>'source', 'routing_edges_static') AS source,
        e.attrs->>'access_id' AS access_id,
        e.attrs->>'from_area' AS from_area,
        e.attrs->>'to_area' AS to_area,
        e.attrs

      FROM public.routing_edges_static e
      WHERE
        (p_floor IS NULL OR e.floor = p_floor)
        AND e.geom && tile_bbox_32640
        AND ST_Intersects(e.geom, tile_bbox_32640)
    ) AS t
    WHERE t.geom IS NOT NULL
  );
END;
$$;


--
-- Name: fn_temp_block_areas_live_mvt(integer, integer, integer, smallint, timestamp with time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_temp_block_areas_live_mvt(z integer, x integer, y integer, p_floor smallint, p_now timestamp with time zone DEFAULT now()) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857 geometry;
  tile_bbox_32640 geometry;
BEGIN
  -- BBOX tile در WebMercator
  tile_bbox_3857  := ST_TileEnvelope(z, x, y);
  tile_bbox_32640 := ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT ST_AsMVT(tile, 'temp_block_areas_live', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(t.geom, 3857),
          tile_bbox_3857,
          4096,
          64,
          true
        ) AS geom,

        t.id,
        t.floor,
        t.restrict_type,
        t.valid_from,
        t.valid_to,
        t.created_by,
        t.reason

      FROM temp_block_areas_live t
      WHERE
        t.floor = p_floor
        AND p_now BETWEEN t.valid_from AND t.valid_to

        -- فیلتر فضایی برای استفاده از GiST و جلوگیری از اسکن کامل
        AND t.geom && tile_bbox_32640
        AND ST_Intersects(t.geom, tile_bbox_32640)
    ) AS tile
    WHERE geom IS NOT NULL
  );
END;
$$;


--
-- Name: fn_update_poi_rating_from_feedbacks(bigint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_update_poi_rating_from_feedbacks(p_poi_id bigint) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_rating_avg   numeric;
  v_rating_count int;
BEGIN
  SELECT
    AVG(rating)::numeric,
    COUNT(*)
  INTO
    v_rating_avg,
    v_rating_count
  FROM public.user_feedbacks
  WHERE
    target_type = 'poi'
    AND target_id = p_poi_id
    AND status = 'approved'
    AND rating IS NOT NULL;

  UPDATE public.poi_points
  SET attrs = jsonb_set(
                jsonb_set(
                  jsonb_set(
                    COALESCE(attrs, '{}'::jsonb),
                    '{rating}',
                    COALESCE(to_jsonb(v_rating_avg), '0'::jsonb),
                    true
                  ),
                  '{rating_count}',
                  COALESCE(to_jsonb(v_rating_count), '0'::jsonb),
                  true
                ),
                '{views}',                       -- 👈 جدید
                COALESCE(to_jsonb(v_rating_count), '0'::jsonb),
                true
              )
  WHERE id = p_poi_id;
END;
$$;


--
-- Name: fn_van_edges_mvt(integer, integer, integer, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_van_edges_mvt(z integer, x integer, y integer, p_floor smallint) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857  geometry;
  tile_bbox_32640 geometry;
BEGIN
  tile_bbox_3857  := ST_TileEnvelope(z, x, y);
  tile_bbox_32640 := ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT ST_AsMVT(tile, 'van_edges', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(e.geom, 3857),
          tile_bbox_3857,
          4096,
          64,
          true
        ) AS geom,

        e.id,
        e.src,
        e.dst,
        e.length_m,
        e.one_way,
        e.is_open,
        e.attrs

      FROM public.van_edges e
      JOIN public.van_nodes ns ON ns.id = e.src
      JOIN public.van_nodes nd ON nd.id = e.dst
      WHERE
        ns.floor = p_floor
        AND nd.floor = p_floor
        AND e.is_open IS TRUE

        -- فیلتر فضایی برای سرعت
        AND e.geom && tile_bbox_32640
        AND ST_Intersects(e.geom, tile_bbox_32640)
    ) AS tile
    WHERE geom IS NOT NULL
  );
END;
$$;


--
-- Name: fn_van_nodes_mvt(integer, integer, integer, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_van_nodes_mvt(z integer, x integer, y integer, p_floor smallint) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857  geometry;
  tile_bbox_32640 geometry;
BEGIN
  tile_bbox_3857  := ST_TileEnvelope(z, x, y);
  tile_bbox_32640 := ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT ST_AsMVT(tile, 'van_nodes', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(n.geom, 3857),
          tile_bbox_3857,
          4096,
          64,
          true
        ) AS geom,

        n.id,
        n.floor,
        n.node_type,
        n.updated_at

      FROM public.van_nodes n
      WHERE
        n.floor = p_floor

        -- فیلتر فضایی برای سرعت
        AND n.geom && tile_bbox_32640
        AND ST_Intersects(n.geom, tile_bbox_32640)
    ) AS tile
    WHERE geom IS NOT NULL
  );
END;
$$;


--
-- Name: fn_walkable_geom_base(smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_walkable_geom_base(p_floor smallint) RETURNS public.geometry
    LANGUAGE plpgsql
    AS $$
DECLARE
  g_base geometry(MultiPolygon, 32640);
BEGIN
  SELECT
    ST_Union(fa.geom)::geometry(MultiPolygon,32640)
  INTO g_base
  FROM fn_allowed_areas(
         now()::timestamptz,   -- زمان فعلی، فقط برای ساخت گراف پایه
         'both'::gender_enum,  -- جنسیت عمومی
         'walk'::text,         -- حالت پیاده
         p_floor
       ) AS fa
  WHERE fa.is_allowed = TRUE;

  RETURN g_base;
END;
$$;


--
-- Name: fn_walkable_geom_cached(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_walkable_geom_cached(p_ts timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS public.geometry
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_bucket timestamptz := date_trunc('minute', p_ts);
  v_geom geometry;
BEGIN
  SELECT c.geom INTO v_geom
  FROM routing_walkable_cache c
  WHERE c.floor = p_floor
    AND c.gender = p_gender
    AND c.mode = p_mode
    AND c.ts_bucket = v_bucket;

  IF v_geom IS NOT NULL THEN
    RETURN v_geom;
  END IF;

  -- تولید واقعی (کند)
  v_geom := fn_walkable_geom_live(p_ts, p_gender, p_mode, p_floor);

  INSERT INTO routing_walkable_cache (floor, gender, mode, ts_bucket, geom, updated_at)
  VALUES (p_floor, p_gender, p_mode, v_bucket, v_geom, now())
  ON CONFLICT (floor, gender, mode, ts_bucket)
  DO UPDATE SET geom = EXCLUDED.geom, updated_at = now();

  RETURN v_geom;
END;
$$;


--
-- Name: fn_walkable_geom_live(timestamp with time zone, public.gender_enum, text, smallint); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_walkable_geom_live(p_now timestamp with time zone, p_gender public.gender_enum, p_mode text, p_floor smallint) RETURNS public.geometry
    LANGUAGE plpgsql
    AS $$
DECLARE
    g_base geometry(MultiPolygon, 32640);
    g_block geometry(MultiPolygon, 32640);
    g_live geometry(MultiPolygon, 32640);

BEGIN

    --------------------------------------------------------------
    -- allowed areas
    --------------------------------------------------------------

    SELECT
        ST_Union(fa.geom)::geometry(MultiPolygon, 32640)

    INTO g_base

    FROM public.fn_allowed_areas(
        p_now,
        p_gender,
        p_mode,
        p_floor
    ) fa

    WHERE fa.is_allowed = TRUE;


    IF g_base IS NULL THEN
        RETURN NULL;
    END IF;


    --------------------------------------------------------------
    -- temporary blocks
    --------------------------------------------------------------

    SELECT
        ST_Union(t.geom)::geometry(MultiPolygon, 32640)

    INTO g_block

    FROM public.temp_block_areas_live t

    WHERE
        t.floor = p_floor

        AND p_now BETWEEN
            t.valid_from
            AND t.valid_to;


    IF g_block IS NULL THEN
        RETURN g_base;
    END IF;


    --------------------------------------------------------------
    -- walkable = allowed areas - temporary blocks
    --------------------------------------------------------------

    g_live :=
        ST_Difference(
            g_base,
            g_block
        );

    RETURN g_live;

END;
$$;


--
-- Name: normalize_shamsi_text_to_iso(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.normalize_shamsi_text_to_iso(p_text text) RETURNS text
    LANGUAGE sql IMMUTABLE
    AS $$
  SELECT
    split_part(p_text,'/',1) || '-' ||
    lpad(split_part(p_text,'/',2), 2, '0') || '-' ||
    lpad(split_part(p_text,'/',3), 2, '0');
$$;


--
-- Name: trg_destinations_set_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_destinations_set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$;


--
-- Name: trg_destinations_sync_geom(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_destinations_sync_geom() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.geom := ST_SetSRID(ST_MakePoint(NEW.x, NEW.y), 32640);
  RETURN NEW;
END;
$$;


--
-- Name: trg_set_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    BEGIN
      NEW.updated_at = now();
      RETURN NEW;
    END;
    $$;


--
-- Name: trg_user_feedbacks_after_change(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_user_feedbacks_after_change() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_poi_id bigint;
BEGIN
  -- updated_at را آپدیت کن
  IF TG_OP = 'INSERT' THEN
    NEW.updated_at := now();
  ELSIF TG_OP = 'UPDATE' THEN
    NEW.updated_at := now();
  END IF;

  -- فقط برای target_type = 'poi'
  IF TG_OP = 'INSERT' THEN
    IF NEW.target_type = 'poi' THEN
      v_poi_id := NEW.target_id;
    END IF;

  ELSIF TG_OP = 'UPDATE' THEN
    -- اگر target_type یا target_id تغییر کرده باشد، قبلی را هم آپدیت کن
    IF OLD.target_type = 'poi' THEN
      PERFORM public.fn_update_poi_rating_from_feedbacks(OLD.target_id);
    END IF;
    IF NEW.target_type = 'poi' THEN
      v_poi_id := NEW.target_id;
    END IF;

  ELSIF TG_OP = 'DELETE' THEN
    IF OLD.target_type = 'poi' THEN
      v_poi_id := OLD.target_id;
    END IF;
  END IF;

  IF v_poi_id IS NOT NULL THEN
    PERFORM public.fn_update_poi_rating_from_feedbacks(v_poi_id);
  END IF;

  IF TG_OP = 'DELETE' THEN
    RETURN OLD;
  ELSE
    RETURN NEW;
  END IF;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: access_prayer_restrictions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.access_prayer_restrictions (
    id bigint NOT NULL,
    entity_table text NOT NULL,
    entity_id bigint NOT NULL,
    prayer_event text NOT NULL,
    before_minutes integer DEFAULT 0 NOT NULL,
    after_minutes integer DEFAULT 0 NOT NULL,
    specific_date date,
    gender public.gender_enum[],
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: access_prayer_restrictions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.access_prayer_restrictions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: access_prayer_restrictions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.access_prayer_restrictions_id_seq OWNED BY public.access_prayer_restrictions.id;


--
-- Name: access_time_restrictions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.access_time_restrictions (
    id bigint NOT NULL,
    entity_table text NOT NULL,
    entity_id bigint NOT NULL,
    gender public.gender_enum[],
    date_scope text[],
    specific_date date,
    start_time time without time zone,
    end_time time without time zone,
    all_hours boolean DEFAULT false NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: access_time_restrictions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.access_time_restrictions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: access_time_restrictions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.access_time_restrictions_id_seq OWNED BY public.access_time_restrictions.id;


--
-- Name: admin_activity_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_activity_logs (
    id bigint NOT NULL,
    user_id bigint,
    action character varying(80) NOT NULL,
    entity_table character varying(120) NOT NULL,
    entity_id bigint,
    meta jsonb DEFAULT '{}'::jsonb NOT NULL,
    ip inet,
    user_agent text,
    created_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: admin_activity_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_activity_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_activity_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_activity_logs_id_seq OWNED BY public.admin_activity_logs.id;


--
-- Name: admin_auth; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_auth (
    user_id bigint NOT NULL,
    password_hash text NOT NULL,
    mfa_enabled boolean DEFAULT false NOT NULL,
    mfa_secret text,
    last_password_change_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: admin_login_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_login_logs (
    id bigint NOT NULL,
    user_id bigint,
    identifier text NOT NULL,
    ip inet,
    user_agent text,
    success boolean NOT NULL,
    failure_reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: admin_login_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_login_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_login_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_login_logs_id_seq OWNED BY public.admin_login_logs.id;


--
-- Name: admin_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_permissions (
    id bigint NOT NULL,
    code text NOT NULL,
    title text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: admin_permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_permissions_id_seq OWNED BY public.admin_permissions.id;


--
-- Name: admin_refresh_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_refresh_tokens (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    token text NOT NULL,
    user_agent text,
    ip inet,
    expires_at timestamp with time zone NOT NULL,
    revoked_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: admin_refresh_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_refresh_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_refresh_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_refresh_tokens_id_seq OWNED BY public.admin_refresh_tokens.id;


--
-- Name: admin_restrictions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_restrictions (
    id bigint NOT NULL,
    target_table text,
    target_id bigint,
    geom public.geometry(MultiPolygon,32640),
    floor smallint,
    restrict_type text DEFAULT 'close'::text NOT NULL,
    penalty_w numeric(10,4) DEFAULT 5.0,
    gender public.gender_enum DEFAULT 'both'::public.gender_enum NOT NULL,
    modes text[] DEFAULT ARRAY['walk'::text, 'wheelchair'::text, 'van'::text] NOT NULL,
    starts_at timestamp with time zone DEFAULT now() NOT NULL,
    ends_at timestamp with time zone,
    schedule jsonb DEFAULT '{}'::jsonb NOT NULL,
    reason text,
    created_by text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT admin_restrictions_floor_check CHECK ((floor = ANY (ARRAY[0, '-1'::integer]))),
    CONSTRAINT admin_restrictions_restrict_type_check CHECK ((restrict_type = ANY (ARRAY['close'::text, 'penalty'::text]))),
    CONSTRAINT admin_restrictions_target_table_check CHECK ((target_table = ANY (ARRAY['areas'::text, 'doors'::text])))
);


--
-- Name: admin_restrictions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_restrictions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_restrictions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_restrictions_id_seq OWNED BY public.admin_restrictions.id;


--
-- Name: admin_role_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_role_permissions (
    role_id bigint NOT NULL,
    permission_id bigint NOT NULL
);


--
-- Name: admin_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_roles (
    id bigint NOT NULL,
    code text NOT NULL,
    title text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone
);


--
-- Name: admin_roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_roles_id_seq OWNED BY public.admin_roles.id;


--
-- Name: admin_user_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_user_roles (
    user_id bigint NOT NULL,
    role_id bigint NOT NULL
);


--
-- Name: areas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.areas (
    id bigint NOT NULL,
    geom public.geometry(MultiPolygon,32640) NOT NULL,
    area_type public.area_type_enum NOT NULL,
    floor smallint NOT NULL,
    allowed_gender public.gender_enum DEFAULT 'both'::public.gender_enum NOT NULL,
    is_closed boolean DEFAULT false NOT NULL,
    weight_open_space numeric(6,3) DEFAULT 1.0 NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT areas_floor_check CHECK ((floor = ANY (ARRAY[0, '-1'::integer])))
);


--
-- Name: areas_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.areas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: areas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.areas_id_seq OWNED BY public.areas.id;


--
-- Name: areas_simplified; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.areas_simplified (
    id bigint NOT NULL,
    geom public.geometry(MultiPolygon,32640) NOT NULL
);


--
-- Name: audio_phrases; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audio_phrases (
    id bigint NOT NULL,
    phrase_key text NOT NULL,
    lang public.lang_enum NOT NULL,
    file_path text NOT NULL,
    meta jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: audio_phrases_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audio_phrases_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audio_phrases_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audio_phrases_id_seq OWNED BY public.audio_phrases.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: calendars; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.calendars (
    d date NOT NULL,
    shamsi text,
    qamari text,
    flags jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.categories (
    id bigint NOT NULL,
    code text NOT NULL,
    label_key text,
    property_target text NOT NULL,
    icon text,
    parent_id bigint,
    level smallint NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    CONSTRAINT categories_check CHECK ((((parent_id IS NULL) AND (level = 1)) OR ((parent_id IS NOT NULL) AND (level > 1)))),
    CONSTRAINT categories_level_check CHECK (((level >= 1) AND (level <= 5)))
);


--
-- Name: categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.categories_id_seq OWNED BY public.categories.id;


--
-- Name: contents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contents (
    id bigint NOT NULL,
    poi_id bigint NOT NULL,
    lang public.lang_enum NOT NULL,
    title text,
    body text,
    media jsonb DEFAULT '[]'::jsonb NOT NULL
);


--
-- Name: contents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.contents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: contents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.contents_id_seq OWNED BY public.contents.id;


--
-- Name: destinations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.destinations (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    title text NOT NULL,
    description text,
    x double precision NOT NULL,
    y double precision NOT NULL,
    floor integer,
    source text NOT NULL,
    source_id text,
    tags jsonb DEFAULT '[]'::jsonb NOT NULL,
    address text,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    geom public.geometry(Point,32640),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT destinations_source_check CHECK ((source = ANY (ARRAY['poi'::text, 'area'::text, 'manual'::text])))
);


--
-- Name: destinations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.destinations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: destinations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.destinations_id_seq OWNED BY public.destinations.id;


--
-- Name: door_access_points; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.door_access_points (
    id bigint NOT NULL,
    door_id bigint NOT NULL,
    geom public.geometry(Point,32640) NOT NULL,
    floor smallint NOT NULL,
    from_area bigint,
    to_area bigint,
    confidence double precision,
    build_method text,
    needs_review boolean DEFAULT false NOT NULL,
    review_reason text,
    snapped_from_geom public.geometry(Point,32640),
    candidates jsonb DEFAULT '[]'::jsonb NOT NULL,
    CONSTRAINT door_access_points_floor_check CHECK ((floor = ANY (ARRAY[0, '-1'::integer])))
);


--
-- Name: door_access_points_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.door_access_points_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: door_access_points_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.door_access_points_id_seq OWNED BY public.door_access_points.id;


--
-- Name: door_schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.door_schedules (
    id bigint NOT NULL,
    door_id bigint NOT NULL,
    dow smallint,
    time_from time without time zone NOT NULL,
    time_to time without time zone NOT NULL,
    rule_type text NOT NULL,
    is_open boolean NOT NULL,
    note text
);


--
-- Name: door_schedules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.door_schedules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: door_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.door_schedules_id_seq OWNED BY public.door_schedules.id;


--
-- Name: door_status_live; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.door_status_live (
    door_id bigint NOT NULL,
    is_open boolean NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    mode text DEFAULT 'normal'::text NOT NULL,
    note text
);


--
-- Name: doors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.doors (
    id bigint NOT NULL,
    geom public.geometry(LineString,32640) NOT NULL,
    from_area bigint,
    to_area bigint,
    floor smallint NOT NULL,
    allowed_gender public.gender_enum DEFAULT 'both'::public.gender_enum NOT NULL,
    is_open boolean DEFAULT true NOT NULL,
    modes text[] DEFAULT ARRAY['walk'::text, 'wheelchair'::text] NOT NULL,
    bidirectional boolean DEFAULT true NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT doors_floor_check CHECK ((floor = ANY (ARRAY[0, '-1'::integer])))
);


--
-- Name: doors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.doors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: doors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.doors_id_seq OWNED BY public.doors.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: feature_group_mappings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.feature_group_mappings (
    id bigint NOT NULL,
    entity_table text NOT NULL,
    feature_key text NOT NULL,
    default_group text NOT NULL,
    default_subgroup text NOT NULL,
    default_node_function text NOT NULL,
    default_types text[] DEFAULT '{}'::text[] NOT NULL,
    default_transport_modes text[] DEFAULT '{}'::text[] NOT NULL,
    default_services jsonb DEFAULT '{}'::jsonb NOT NULL,
    default_gender text DEFAULT 'both'::text NOT NULL,
    category_leaf_id bigint,
    CONSTRAINT feature_group_mappings_entity_table_check CHECK ((entity_table = ANY (ARRAY['areas'::text, 'doors'::text, 'poi_points'::text, 'van_nodes'::text, 'qrcodes'::text])))
);


--
-- Name: feature_group_mappings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.feature_group_mappings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: feature_group_mappings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.feature_group_mappings_id_seq OWNED BY public.feature_group_mappings.id;


--
-- Name: featured_landmark_places; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.featured_landmark_places (
    id bigint NOT NULL,
    poi_id bigint NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: featured_landmark_places_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.featured_landmark_places_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: featured_landmark_places_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.featured_landmark_places_id_seq OWNED BY public.featured_landmark_places.id;


--
-- Name: guidance_point_images; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.guidance_point_images (
    id bigint NOT NULL,
    point_id bigint NOT NULL,
    image_url character varying(2048) NOT NULL,
    image_key character varying(1024) NOT NULL,
    sort_order smallint DEFAULT '1'::smallint NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    view_orientation character varying(20),
    azimuth_deg numeric,
    fov_deg numeric DEFAULT 60 NOT NULL,
    caption character varying(160),
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT guidance_point_images_azimuth_chk CHECK (((azimuth_deg IS NULL) OR ((azimuth_deg >= (0)::numeric) AND (azimuth_deg < (360)::numeric)))),
    CONSTRAINT guidance_point_images_fov_chk CHECK (((fov_deg > (0)::numeric) AND (fov_deg <= (180)::numeric))),
    CONSTRAINT guidance_point_images_orientation_chk CHECK (((view_orientation IS NULL) OR ((view_orientation)::text = ANY ((ARRAY['north'::character varying, 'north_east'::character varying, 'east'::character varying, 'south_east'::character varying, 'south'::character varying, 'south_west'::character varying, 'west'::character varying, 'north_west'::character varying, 'unknown'::character varying])::text[]))))
);


--
-- Name: guidance_point_images_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.guidance_point_images_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: guidance_point_images_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.guidance_point_images_id_seq OWNED BY public.guidance_point_images.id;


--
-- Name: guidance_points; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.guidance_points (
    id bigint NOT NULL,
    floor smallint NOT NULL,
    area_id bigint,
    title character varying(160),
    description text,
    x numeric(12,3) NOT NULL,
    y numeric(13,3) NOT NULL,
    view_direction character varying(40),
    azimuth_deg numeric(6,2),
    coverage_radius_m numeric(8,2) DEFAULT '10'::numeric NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    primary_image_url character varying(2048),
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    geom public.geometry(Point,32640),
    CONSTRAINT guidance_points_azimuth_chk CHECK (((azimuth_deg IS NULL) OR ((azimuth_deg >= (0)::numeric) AND (azimuth_deg < (360)::numeric)))),
    CONSTRAINT guidance_points_floor_chk CHECK ((floor = ANY (ARRAY['-1'::integer, 0]))),
    CONSTRAINT guidance_points_radius_chk CHECK (((coverage_radius_m > (0)::numeric) AND (coverage_radius_m <= (100)::numeric)))
);


--
-- Name: guidance_points_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.guidance_points_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: guidance_points_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.guidance_points_id_seq OWNED BY public.guidance_points.id;


--
-- Name: i18n_texts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.i18n_texts (
    id bigint NOT NULL,
    entity_table text NOT NULL,
    entity_id bigint NOT NULL,
    field text NOT NULL,
    lang public.lang_enum NOT NULL,
    txt text NOT NULL,
    CONSTRAINT i18n_texts_entity_table_check CHECK ((entity_table = ANY (ARRAY['areas'::text, 'doors'::text, 'poi_points'::text, 'van_nodes'::text, 'qrcodes'::text, 'categories'::text, 'pages'::text, 'page_faqs'::text]))),
    CONSTRAINT i18n_texts_field_check CHECK ((field = ANY (ARRAY['name'::text, 'short'::text, 'desc'::text, 'description'::text, 'address'::text, 'question'::text, 'answer'::text])))
);


--
-- Name: i18n_texts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.i18n_texts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: i18n_texts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.i18n_texts_id_seq OWNED BY public.i18n_texts.id;


--
-- Name: instruction_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.instruction_templates (
    id bigint NOT NULL,
    template_key text NOT NULL,
    lang public.lang_enum NOT NULL,
    template text NOT NULL
);


--
-- Name: instruction_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.instruction_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: instruction_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.instruction_templates_id_seq OWNED BY public.instruction_templates.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: languages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.languages (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    english_name character varying(255) NOT NULL,
    locale character varying(255) NOT NULL,
    code character varying(8) NOT NULL,
    direction character varying(3) DEFAULT 'ltr'::character varying NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    flag_icon_url character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT languages_direction_check CHECK (((direction)::text = ANY ((ARRAY['ltr'::character varying, 'rtl'::character varying])::text[])))
);


--
-- Name: languages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.languages_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: languages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.languages_id_seq OWNED BY public.languages.id;


--
-- Name: mesh_adjacency; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mesh_adjacency (
    id bigint NOT NULL,
    tri_a bigint NOT NULL,
    tri_b bigint NOT NULL,
    door_id bigint,
    cost_w numeric(10,4) DEFAULT 1.0 NOT NULL,
    gate_point public.geometry(Point,32640)
);


--
-- Name: mesh_adjacency_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.mesh_adjacency_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: mesh_adjacency_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.mesh_adjacency_id_seq OWNED BY public.mesh_adjacency.id;


--
-- Name: mesh_triangles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mesh_triangles (
    id bigint NOT NULL,
    geom public.geometry(Polygon,32640) NOT NULL,
    floor smallint NOT NULL,
    area_id bigint,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT mesh_triangles_floor_check CHECK ((floor = ANY (ARRAY[0, '-1'::integer])))
);


--
-- Name: temp_block_areas_live; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.temp_block_areas_live (
    id bigint NOT NULL,
    floor smallint NOT NULL,
    geom public.geometry(Polygon,32640) NOT NULL,
    restrict_type text DEFAULT 'close'::text NOT NULL,
    valid_from timestamp with time zone DEFAULT now() NOT NULL,
    valid_to timestamp with time zone DEFAULT (now() + '01:00:00'::interval),
    created_by bigint,
    reason text,
    created_at timestamp(6) with time zone DEFAULT now() NOT NULL,
    title text,
    is_active boolean DEFAULT true NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: v_doors_live; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_doors_live AS
 WITH scheduled AS (
         SELECT s.door_id,
            s.dow,
            s.time_from,
            s.time_to,
            s.is_open,
            s.rule_type
           FROM public.door_schedules s
        )
 SELECT d.id,
    d.geom,
    d.from_area,
    d.to_area,
    d.floor,
    d.allowed_gender,
    d.is_open,
    d.modes,
    d.bidirectional,
    d.attrs,
    d.updated_at,
    COALESCE(ls.is_open, d.is_open) AS is_open_effective
   FROM (public.doors d
     LEFT JOIN public.door_status_live ls ON ((ls.door_id = d.id)));


--
-- Name: v_temp_block_areas_active; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_temp_block_areas_active AS
 SELECT id,
    floor,
    geom,
    restrict_type,
    valid_from,
    valid_to,
    created_by,
    reason,
    created_at,
    title,
    is_active,
    updated_at
   FROM public.temp_block_areas_live
  WHERE ((is_active = true) AND (valid_from <= now()) AND ((valid_to IS NULL) OR (valid_to >= now())));


--
-- Name: mesh_adjacency_live; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.mesh_adjacency_live AS
 SELECT ma.id,
    ma.tri_a,
    ma.tri_b,
    ma.door_id,
    ma.cost_w,
    ma.gate_point
   FROM (((public.mesh_adjacency ma
     JOIN public.mesh_triangles ta ON ((ta.id = ma.tri_a)))
     LEFT JOIN public.v_doors_live d ON ((ma.door_id = d.id)))
     LEFT JOIN public.v_temp_block_areas_active b ON (((b.floor = ta.floor) AND public.st_intersects(ma.gate_point, b.geom))))
  WHERE ((COALESCE(d.is_open_effective, true) = true) AND (b.id IS NULL));


--
-- Name: mesh_triangles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.mesh_triangles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: mesh_triangles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.mesh_triangles_id_seq OWNED BY public.mesh_triangles.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: mv_area_door_stats; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.mv_area_door_stats AS
 SELECT a.id AS area_id,
    a.floor,
    (count(dap.*))::integer AS door_cnt
   FROM (public.areas a
     LEFT JOIN public.door_access_points dap ON (((dap.floor = a.floor) AND (COALESCE(dap.needs_review, false) = false) AND (dap.from_area IS NOT NULL) AND (dap.to_area IS NOT NULL) AND ((dap.from_area = a.id) OR (dap.to_area = a.id)))))
  GROUP BY a.id, a.floor
  WITH NO DATA;


--
-- Name: page_faqs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.page_faqs (
    id bigint NOT NULL,
    question text NOT NULL,
    answer text NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: page_faqs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.page_faqs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: page_faqs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.page_faqs_id_seq OWNED BY public.page_faqs.id;


--
-- Name: pages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pages (
    id bigint NOT NULL,
    type text NOT NULL,
    title text NOT NULL,
    description text,
    phones jsonb DEFAULT '[]'::jsonb NOT NULL,
    emails jsonb DEFAULT '[]'::jsonb NOT NULL,
    address text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT pages_emails_max3 CHECK (((jsonb_typeof(emails) = 'array'::text) AND (jsonb_array_length(emails) <= 3))),
    CONSTRAINT pages_phones_max3 CHECK (((jsonb_typeof(phones) = 'array'::text) AND (jsonb_array_length(phones) <= 3))),
    CONSTRAINT pages_type_check CHECK ((type = ANY (ARRAY['support'::text, 'rules'::text, 'about'::text, 'contact'::text])))
);


--
-- Name: pages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pages_id_seq OWNED BY public.pages.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: poi_points; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.poi_points (
    id bigint NOT NULL,
    geom public.geometry(Point,32640) NOT NULL,
    poi_type public.poi_type_enum NOT NULL,
    floor smallint NOT NULL,
    has_content boolean DEFAULT false NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    category_leaf_id bigint,
    CONSTRAINT poi_points_floor_check CHECK ((floor = ANY (ARRAY[0, '-1'::integer])))
);


--
-- Name: poi_points_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.poi_points_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: poi_points_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.poi_points_id_seq OWNED BY public.poi_points.id;


--
-- Name: user_feedbacks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_feedbacks (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    target_type public.feedback_target_enum NOT NULL,
    target_id bigint NOT NULL,
    lang public.lang_enum NOT NULL,
    rating smallint,
    title text,
    body text,
    status public.feedback_status_enum DEFAULT 'pending'::public.feedback_status_enum NOT NULL,
    admin_note text,
    route_log_id bigint,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    approved_at timestamp with time zone,
    approved_by bigint,
    CONSTRAINT user_feedbacks_rating_check CHECK (((rating IS NULL) OR ((rating >= 1) AND (rating <= 5))))
);


--
-- Name: poi_ratings; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.poi_ratings AS
 SELECT target_id AS poi_id,
    count(*) FILTER (WHERE (rating IS NOT NULL)) AS rating_count,
    (avg(rating) FILTER (WHERE (rating IS NOT NULL)))::numeric(3,2) AS rating_avg,
    max(created_at) AS last_review_at
   FROM public.user_feedbacks
  WHERE ((target_type = 'poi'::public.feedback_target_enum) AND (status = 'approved'::public.feedback_status_enum))
  GROUP BY target_id;


--
-- Name: prayer_times; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.prayer_times (
    d_sh character varying(10),
    d_greg date NOT NULL,
    d_hejri character varying(20),
    weekday_fa character varying(10),
    fajr time(6) without time zone,
    sunrise time(6) without time zone,
    dhuhr time(6) without time zone,
    sunset time(6) without time zone,
    maghrib time(6) without time zone,
    midnight time(6) without time zone
);


--
-- Name: prayer_times_stg; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.prayer_times_stg (
    weekday_fa_text text,
    shamsi_text text,
    fajr_text text,
    sunrise_text text,
    dhuhr_text text,
    sunset_text text,
    maghrib_text text,
    midnight_text text,
    gregorian_text text,
    hejri_text text,
    sun_qibla_text text,
    fasting_text text
);


--
-- Name: qrcodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.qrcodes (
    id bigint NOT NULL,
    code text NOT NULL,
    geom public.geometry(Point,32640) NOT NULL,
    target_type public.target_type_enum NOT NULL,
    target_id bigint,
    version integer DEFAULT 1 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: qrcodes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.qrcodes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: qrcodes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.qrcodes_id_seq OWNED BY public.qrcodes.id;


--
-- Name: route_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.route_logs (
    id bigint NOT NULL,
    ts timestamp with time zone DEFAULT now() NOT NULL,
    mode text NOT NULL,
    gender public.gender_enum NOT NULL,
    floor smallint,
    origin_type text,
    destination_type text,
    distance_m numeric(10,2),
    duration_s numeric(10,2),
    ok boolean DEFAULT true NOT NULL,
    meta jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT route_logs_floor_check CHECK ((floor = ANY (ARRAY[0, '-1'::integer]))),
    CONSTRAINT route_logs_mode_check CHECK ((mode = ANY (ARRAY['walk'::text, 'wheelchair'::text, 'van'::text, 'multi'::text])))
);


--
-- Name: route_logs_debug; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.route_logs_debug (
    id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    algo text NOT NULL,
    mode text NOT NULL,
    gender public.gender_enum NOT NULL,
    floor smallint,
    origin_geom public.geometry(Point,32640),
    dest_geom public.geometry(Point,32640),
    origin_node_id bigint,
    dest_node_id bigint,
    last_node_id bigint,
    last_node_geom public.geometry(Point,32640),
    status text NOT NULL,
    details jsonb
);


--
-- Name: route_logs_debug_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.route_logs_debug_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: route_logs_debug_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.route_logs_debug_id_seq OWNED BY public.route_logs_debug.id;


--
-- Name: route_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.route_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: route_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.route_logs_id_seq OWNED BY public.route_logs.id;


--
-- Name: routing_edge_areas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.routing_edge_areas (
    edge_id bigint NOT NULL,
    area_id bigint NOT NULL,
    floor smallint NOT NULL
);


--
-- Name: routing_edges_static; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.routing_edges_static (
    id bigint NOT NULL,
    floor smallint NOT NULL,
    src bigint NOT NULL,
    dst bigint NOT NULL,
    geom public.geometry(LineString,32640) NOT NULL,
    base_cost numeric NOT NULL,
    door_id bigint,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: routing_nodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.routing_nodes (
    id bigint NOT NULL,
    floor smallint NOT NULL,
    geom public.geometry(Point,32640) NOT NULL,
    kind text NOT NULL,
    ref_table text,
    ref_id bigint,
    area_id bigint
);


--
-- Name: routing_edges_live; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.routing_edges_live AS
 WITH access_nodes AS (
         SELECT rn.id AS node_id,
            rn.ref_id AS access_id,
            dap.door_id,
            dap.from_area,
            dap.to_area
           FROM (public.routing_nodes rn
             JOIN public.door_access_points dap ON ((dap.id = rn.ref_id)))
          WHERE ((rn.ref_table = 'door_access_points'::text) AND (rn.ref_id IS NOT NULL))
        ), edge_access AS (
         SELECT e.id,
            e.floor,
            e.src,
            e.dst,
            e.geom,
            e.base_cost,
            e.door_id AS edge_door_id,
            e.attrs,
            san.access_id AS src_access_id,
            san.door_id AS src_door_id,
            dan.access_id AS dst_access_id,
            dan.door_id AS dst_door_id
           FROM ((public.routing_edges_static e
             LEFT JOIN access_nodes san ON ((san.node_id = e.src)))
             LEFT JOIN access_nodes dan ON ((dan.node_id = e.dst)))
        )
 SELECT ea.id,
    ea.floor,
    ea.src,
    ea.dst,
    ea.geom,
    ea.base_cost,
    (((ea.base_cost * COALESCE(src_acc.penalty_w, (1)::numeric)) * COALESCE(dst_acc.penalty_w, (1)::numeric)) * COALESCE(edge_acc.penalty_w, (1)::numeric)) AS cost,
    COALESCE(ea.edge_door_id, ea.src_door_id, ea.dst_door_id) AS door_id
   FROM ((((((edge_access ea
     LEFT JOIN public.v_doors_live sd ON ((sd.id = ea.src_door_id)))
     LEFT JOIN public.v_doors_live dd ON ((dd.id = ea.dst_door_id)))
     LEFT JOIN public.v_doors_live ed ON ((ed.id = ea.edge_door_id)))
     LEFT JOIN LATERAL ( SELECT a.allowed,
            a.penalty_w
           FROM public.fn_entity_access('doors'::text, ea.src_door_id, now(), 'both'::public.gender_enum, 'walk'::text, ea.floor, public.st_pointonsurface(ea.geom)) a(allowed, penalty_w, restriction_type)
         LIMIT 1) src_acc ON ((ea.src_door_id IS NOT NULL)))
     LEFT JOIN LATERAL ( SELECT a.allowed,
            a.penalty_w
           FROM public.fn_entity_access('doors'::text, ea.dst_door_id, now(), 'both'::public.gender_enum, 'walk'::text, ea.floor, public.st_pointonsurface(ea.geom)) a(allowed, penalty_w, restriction_type)
         LIMIT 1) dst_acc ON ((ea.dst_door_id IS NOT NULL)))
     LEFT JOIN LATERAL ( SELECT a.allowed,
            a.penalty_w
           FROM public.fn_entity_access('doors'::text, ea.edge_door_id, now(), 'both'::public.gender_enum, 'walk'::text, ea.floor, public.st_pointonsurface(ea.geom)) a(allowed, penalty_w, restriction_type)
         LIMIT 1) edge_acc ON ((ea.edge_door_id IS NOT NULL)))
  WHERE ((COALESCE(sd.is_open_effective, true) = true) AND (COALESCE(dd.is_open_effective, true) = true) AND (COALESCE(ed.is_open_effective, true) = true) AND (COALESCE(src_acc.allowed, true) = true) AND (COALESCE(dst_acc.allowed, true) = true) AND (COALESCE(edge_acc.allowed, true) = true) AND (COALESCE((ea.attrs ->> 'safety'::text), ''::text) <> 'unsafe_direct_fallback'::text) AND (COALESCE((ea.attrs ->> 'valid_inside_area'::text), 'true'::text) = 'true'::text));


--
-- Name: routing_edges_static_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.routing_edges_static_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: routing_edges_static_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.routing_edges_static_id_seq OWNED BY public.routing_edges_static.id;


--
-- Name: routing_nodes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.routing_nodes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: routing_nodes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.routing_nodes_id_seq OWNED BY public.routing_nodes.id;


--
-- Name: routing_walkable_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.routing_walkable_cache (
    floor smallint NOT NULL,
    gender public.gender_enum NOT NULL,
    mode text NOT NULL,
    ts_bucket timestamp with time zone NOT NULL,
    geom public.geometry,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: rule_sets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rule_sets (
    id bigint NOT NULL,
    name text NOT NULL,
    precedence text[] DEFAULT ARRAY['qamari'::text, 'hourly'::text, 'shamsi'::text] NOT NULL
);


--
-- Name: rule_sets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rule_sets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rule_sets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rule_sets_id_seq OWNED BY public.rule_sets.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: support_feedbacks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.support_feedbacks (
    id bigint NOT NULL,
    user_id bigint,
    subject character varying(255) NOT NULL,
    message text NOT NULL,
    status text DEFAULT 'new'::text NOT NULL,
    meta jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT support_feedbacks_status_check CHECK ((status = ANY (ARRAY['new'::text, 'seen'::text, 'in_progress'::text, 'closed'::text])))
);


--
-- Name: support_feedbacks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.support_feedbacks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: support_feedbacks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.support_feedbacks_id_seq OWNED BY public.support_feedbacks.id;


--
-- Name: temp_block_areas_live_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.temp_block_areas_live_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: temp_block_areas_live_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.temp_block_areas_live_id_seq OWNED BY public.temp_block_areas_live.id;


--
-- Name: user_feedbacks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_feedbacks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_feedbacks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_feedbacks_id_seq OWNED BY public.user_feedbacks.id;


--
-- Name: user_login_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_login_logs (
    id bigint NOT NULL,
    user_id bigint,
    identifier text,
    ip inet,
    user_agent text,
    success boolean DEFAULT false NOT NULL,
    failure_reason text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: user_login_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_login_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_login_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_login_logs_id_seq OWNED BY public.user_login_logs.id;


--
-- Name: user_refresh_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_refresh_tokens (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    token text NOT NULL,
    user_agent text,
    ip inet,
    expires_at timestamp with time zone NOT NULL,
    revoked_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: user_refresh_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_refresh_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_refresh_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_refresh_tokens_id_seq OWNED BY public.user_refresh_tokens.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255),
    email_verified_at timestamp(0) without time zone,
    password character varying(255),
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    username character varying(100),
    is_admin boolean DEFAULT false NOT NULL,
    status text DEFAULT 'active'::text NOT NULL,
    last_login_at timestamp with time zone,
    last_login_ip inet,
    failed_login_attempts integer DEFAULT 0 NOT NULL,
    locked_until timestamp with time zone,
    is_active boolean DEFAULT true NOT NULL,
    mobile text,
    otp_hash text,
    otp_expires_at timestamp with time zone,
    otp_last_sent_at timestamp with time zone,
    national_id text,
    gender text,
    birth_date date,
    address jsonb DEFAULT '{}'::jsonb,
    preferences jsonb DEFAULT '{}'::jsonb,
    avatar_url text,
    referral_code text,
    CONSTRAINT users_status_check CHECK ((status = ANY (ARRAY['active'::text, 'locked'::text, 'disabled'::text])))
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: v_allowed_areas; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_allowed_areas AS
 SELECT id,
    geom,
    area_type,
    floor,
    allowed_gender,
    is_closed,
    weight_open_space,
    attrs,
    is_allowed,
    admin_penalty_w
   FROM public.fn_allowed_areas(now(), 'male'::public.gender_enum, 'walk'::text, (0)::smallint) fn_allowed_areas(id, geom, area_type, floor, allowed_gender, is_closed, weight_open_space, attrs, is_allowed, admin_penalty_w);


--
-- Name: v_allowed_doors; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_allowed_doors AS
 SELECT id,
    geom,
    from_area,
    to_area,
    floor,
    allowed_gender,
    is_open,
    modes,
    bidirectional,
    attrs,
    is_allowed,
    admin_penalty_w
   FROM public.fn_allowed_doors(now(), 'male'::public.gender_enum, 'walk'::text, (0)::smallint) fn_allowed_doors(id, geom, from_area, to_area, floor, allowed_gender, is_open, modes, bidirectional, attrs, is_allowed, admin_penalty_w);


--
-- Name: van_edges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.van_edges (
    id bigint NOT NULL,
    src bigint NOT NULL,
    dst bigint NOT NULL,
    length_m numeric(8,2),
    one_way boolean DEFAULT true NOT NULL,
    is_open boolean DEFAULT true NOT NULL,
    attrs jsonb DEFAULT '{}'::jsonb NOT NULL,
    geom public.geometry(LineString,32640)
);


--
-- Name: van_edges_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.van_edges_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: van_edges_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.van_edges_id_seq OWNED BY public.van_edges.id;


--
-- Name: van_nodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.van_nodes (
    id bigint NOT NULL,
    geom public.geometry(Point,32640) NOT NULL,
    node_type public.van_node_type_enum DEFAULT 'junction'::public.van_node_type_enum NOT NULL,
    floor smallint NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT van_nodes_floor_check CHECK ((floor = ANY (ARRAY[0, '-1'::integer])))
);


--
-- Name: van_nodes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.van_nodes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: van_nodes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.van_nodes_id_seq OWNED BY public.van_nodes.id;


--
-- Name: vw_door_status_live_ext; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_door_status_live_ext AS
 SELECT s.door_id,
    d.floor,
    d.geom,
    d.from_area,
    d.to_area,
    d.modes,
    d.allowed_gender,
    s.base_is_open,
    s.base_status,
    s.live_status,
    s.limited_by_time,
    s.limited_by_prayer,
    s.active_time_title,
    s.active_prayer_title,
    s.active_rule_type,
    s.active_rule_id,
    (s.live_status <> 'open'::text) AS is_closed,
    it_fa.txt AS title_fa,
    it_en.txt AS title_en,
    it_ar.txt AS title_ar,
    it_ur.txt AS title_ur
   FROM (((((public.fn_door_live_status_ext() s(door_id, floor, base_is_open, base_status, live_status, limited_by_time, limited_by_prayer, active_time_title, active_prayer_title, active_rule_type, active_rule_id)
     JOIN public.doors d ON ((d.id = s.door_id)))
     LEFT JOIN public.i18n_texts it_fa ON (((it_fa.entity_table = 'doors'::text) AND (it_fa.entity_id = d.id) AND (it_fa.field = 'name'::text) AND (it_fa.lang = 'fa'::public.lang_enum))))
     LEFT JOIN public.i18n_texts it_en ON (((it_en.entity_table = 'doors'::text) AND (it_en.entity_id = d.id) AND (it_en.field = 'name'::text) AND (it_en.lang = 'en'::public.lang_enum))))
     LEFT JOIN public.i18n_texts it_ar ON (((it_ar.entity_table = 'doors'::text) AND (it_ar.entity_id = d.id) AND (it_ar.field = 'name'::text) AND (it_ar.lang = 'ar'::public.lang_enum))))
     LEFT JOIN public.i18n_texts it_ur ON (((it_ur.entity_table = 'doors'::text) AND (it_ur.entity_id = d.id) AND (it_ur.field = 'name'::text) AND (it_ur.lang = 'ur'::public.lang_enum))));


--
-- Name: vw_doors_live_status; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_doors_live_status AS
 SELECT id AS door_id,
    floor,
    from_area,
    to_area,
    allowed_gender,
    modes,
    is_open AS is_open_layer,
    public.fn_door_live_status(id, now(), NULL::text) AS live_status,
    (public.fn_door_live_status(id, now(), NULL::text) <> 'open'::public.door_live_status_enum) AS is_closed
   FROM public.doors d;


--
-- Name: vw_mesh_triangles; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.vw_mesh_triangles AS
 SELECT id,
    geom,
    floor,
    area_id,
    attrs
   FROM public.mesh_triangles
  WHERE (floor = 0);


--
-- Name: access_prayer_restrictions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.access_prayer_restrictions ALTER COLUMN id SET DEFAULT nextval('public.access_prayer_restrictions_id_seq'::regclass);


--
-- Name: access_time_restrictions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.access_time_restrictions ALTER COLUMN id SET DEFAULT nextval('public.access_time_restrictions_id_seq'::regclass);


--
-- Name: admin_activity_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_activity_logs ALTER COLUMN id SET DEFAULT nextval('public.admin_activity_logs_id_seq'::regclass);


--
-- Name: admin_login_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_login_logs ALTER COLUMN id SET DEFAULT nextval('public.admin_login_logs_id_seq'::regclass);


--
-- Name: admin_permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_permissions ALTER COLUMN id SET DEFAULT nextval('public.admin_permissions_id_seq'::regclass);


--
-- Name: admin_refresh_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_refresh_tokens ALTER COLUMN id SET DEFAULT nextval('public.admin_refresh_tokens_id_seq'::regclass);


--
-- Name: admin_restrictions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_restrictions ALTER COLUMN id SET DEFAULT nextval('public.admin_restrictions_id_seq'::regclass);


--
-- Name: admin_roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_roles ALTER COLUMN id SET DEFAULT nextval('public.admin_roles_id_seq'::regclass);


--
-- Name: areas id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.areas ALTER COLUMN id SET DEFAULT nextval('public.areas_id_seq'::regclass);


--
-- Name: audio_phrases id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audio_phrases ALTER COLUMN id SET DEFAULT nextval('public.audio_phrases_id_seq'::regclass);


--
-- Name: categories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories ALTER COLUMN id SET DEFAULT nextval('public.categories_id_seq'::regclass);


--
-- Name: contents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contents ALTER COLUMN id SET DEFAULT nextval('public.contents_id_seq'::regclass);


--
-- Name: destinations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.destinations ALTER COLUMN id SET DEFAULT nextval('public.destinations_id_seq'::regclass);


--
-- Name: door_access_points id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.door_access_points ALTER COLUMN id SET DEFAULT nextval('public.door_access_points_id_seq'::regclass);


--
-- Name: door_schedules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.door_schedules ALTER COLUMN id SET DEFAULT nextval('public.door_schedules_id_seq'::regclass);


--
-- Name: doors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doors ALTER COLUMN id SET DEFAULT nextval('public.doors_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: feature_group_mappings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feature_group_mappings ALTER COLUMN id SET DEFAULT nextval('public.feature_group_mappings_id_seq'::regclass);


--
-- Name: featured_landmark_places id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.featured_landmark_places ALTER COLUMN id SET DEFAULT nextval('public.featured_landmark_places_id_seq'::regclass);


--
-- Name: guidance_point_images id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_point_images ALTER COLUMN id SET DEFAULT nextval('public.guidance_point_images_id_seq'::regclass);


--
-- Name: guidance_points id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_points ALTER COLUMN id SET DEFAULT nextval('public.guidance_points_id_seq'::regclass);


--
-- Name: i18n_texts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_texts ALTER COLUMN id SET DEFAULT nextval('public.i18n_texts_id_seq'::regclass);


--
-- Name: instruction_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.instruction_templates ALTER COLUMN id SET DEFAULT nextval('public.instruction_templates_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: languages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.languages ALTER COLUMN id SET DEFAULT nextval('public.languages_id_seq'::regclass);


--
-- Name: mesh_adjacency id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_adjacency ALTER COLUMN id SET DEFAULT nextval('public.mesh_adjacency_id_seq'::regclass);


--
-- Name: mesh_triangles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_triangles ALTER COLUMN id SET DEFAULT nextval('public.mesh_triangles_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: page_faqs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.page_faqs ALTER COLUMN id SET DEFAULT nextval('public.page_faqs_id_seq'::regclass);


--
-- Name: pages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pages ALTER COLUMN id SET DEFAULT nextval('public.pages_id_seq'::regclass);


--
-- Name: poi_points id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.poi_points ALTER COLUMN id SET DEFAULT nextval('public.poi_points_id_seq'::regclass);


--
-- Name: qrcodes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qrcodes ALTER COLUMN id SET DEFAULT nextval('public.qrcodes_id_seq'::regclass);


--
-- Name: route_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.route_logs ALTER COLUMN id SET DEFAULT nextval('public.route_logs_id_seq'::regclass);


--
-- Name: route_logs_debug id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.route_logs_debug ALTER COLUMN id SET DEFAULT nextval('public.route_logs_debug_id_seq'::regclass);


--
-- Name: routing_edges_static id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.routing_edges_static ALTER COLUMN id SET DEFAULT nextval('public.routing_edges_static_id_seq'::regclass);


--
-- Name: routing_nodes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.routing_nodes ALTER COLUMN id SET DEFAULT nextval('public.routing_nodes_id_seq'::regclass);


--
-- Name: rule_sets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rule_sets ALTER COLUMN id SET DEFAULT nextval('public.rule_sets_id_seq'::regclass);


--
-- Name: support_feedbacks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_feedbacks ALTER COLUMN id SET DEFAULT nextval('public.support_feedbacks_id_seq'::regclass);


--
-- Name: temp_block_areas_live id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.temp_block_areas_live ALTER COLUMN id SET DEFAULT nextval('public.temp_block_areas_live_id_seq'::regclass);


--
-- Name: user_feedbacks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_feedbacks ALTER COLUMN id SET DEFAULT nextval('public.user_feedbacks_id_seq'::regclass);


--
-- Name: user_login_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_login_logs ALTER COLUMN id SET DEFAULT nextval('public.user_login_logs_id_seq'::regclass);


--
-- Name: user_refresh_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_refresh_tokens ALTER COLUMN id SET DEFAULT nextval('public.user_refresh_tokens_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: van_edges id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.van_edges ALTER COLUMN id SET DEFAULT nextval('public.van_edges_id_seq'::regclass);


--
-- Name: van_nodes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.van_nodes ALTER COLUMN id SET DEFAULT nextval('public.van_nodes_id_seq'::regclass);


--
-- Name: access_prayer_restrictions access_prayer_restrictions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.access_prayer_restrictions
    ADD CONSTRAINT access_prayer_restrictions_pkey PRIMARY KEY (id);


--
-- Name: access_time_restrictions access_time_restrictions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.access_time_restrictions
    ADD CONSTRAINT access_time_restrictions_pkey PRIMARY KEY (id);


--
-- Name: admin_activity_logs admin_activity_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_activity_logs
    ADD CONSTRAINT admin_activity_logs_pkey PRIMARY KEY (id);


--
-- Name: admin_auth admin_auth_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_auth
    ADD CONSTRAINT admin_auth_pkey PRIMARY KEY (user_id);


--
-- Name: admin_login_logs admin_login_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_login_logs
    ADD CONSTRAINT admin_login_logs_pkey PRIMARY KEY (id);


--
-- Name: admin_permissions admin_permissions_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_permissions
    ADD CONSTRAINT admin_permissions_code_key UNIQUE (code);


--
-- Name: admin_permissions admin_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_permissions
    ADD CONSTRAINT admin_permissions_pkey PRIMARY KEY (id);


--
-- Name: admin_refresh_tokens admin_refresh_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_refresh_tokens
    ADD CONSTRAINT admin_refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: admin_refresh_tokens admin_refresh_tokens_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_refresh_tokens
    ADD CONSTRAINT admin_refresh_tokens_token_key UNIQUE (token);


--
-- Name: admin_restrictions admin_restrictions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_restrictions
    ADD CONSTRAINT admin_restrictions_pkey PRIMARY KEY (id);


--
-- Name: admin_role_permissions admin_role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_role_permissions
    ADD CONSTRAINT admin_role_permissions_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: admin_roles admin_roles_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_roles
    ADD CONSTRAINT admin_roles_code_key UNIQUE (code);


--
-- Name: admin_roles admin_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_roles
    ADD CONSTRAINT admin_roles_pkey PRIMARY KEY (id);


--
-- Name: admin_user_roles admin_user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_user_roles
    ADD CONSTRAINT admin_user_roles_pkey PRIMARY KEY (user_id, role_id);


--
-- Name: areas areas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.areas
    ADD CONSTRAINT areas_pkey PRIMARY KEY (id);


--
-- Name: areas_simplified areas_simplified_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.areas_simplified
    ADD CONSTRAINT areas_simplified_pkey PRIMARY KEY (id);


--
-- Name: audio_phrases audio_phrases_phrase_key_lang_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audio_phrases
    ADD CONSTRAINT audio_phrases_phrase_key_lang_key UNIQUE (phrase_key, lang);


--
-- Name: audio_phrases audio_phrases_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audio_phrases
    ADD CONSTRAINT audio_phrases_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: calendars calendars_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.calendars
    ADD CONSTRAINT calendars_pkey PRIMARY KEY (d);


--
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- Name: contents contents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contents
    ADD CONSTRAINT contents_pkey PRIMARY KEY (id);


--
-- Name: contents contents_poi_id_lang_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contents
    ADD CONSTRAINT contents_poi_id_lang_key UNIQUE (poi_id, lang);


--
-- Name: destinations destinations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.destinations
    ADD CONSTRAINT destinations_pkey PRIMARY KEY (id);


--
-- Name: door_access_points door_access_points_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.door_access_points
    ADD CONSTRAINT door_access_points_pkey PRIMARY KEY (id);


--
-- Name: door_schedules door_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.door_schedules
    ADD CONSTRAINT door_schedules_pkey PRIMARY KEY (id);


--
-- Name: door_status_live door_status_live_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.door_status_live
    ADD CONSTRAINT door_status_live_pkey PRIMARY KEY (door_id);


--
-- Name: doors doors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doors
    ADD CONSTRAINT doors_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: feature_group_mappings feature_group_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feature_group_mappings
    ADD CONSTRAINT feature_group_mappings_pkey PRIMARY KEY (id);


--
-- Name: featured_landmark_places featured_landmark_places_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.featured_landmark_places
    ADD CONSTRAINT featured_landmark_places_pkey PRIMARY KEY (id);


--
-- Name: featured_landmark_places featured_landmark_places_poi_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.featured_landmark_places
    ADD CONSTRAINT featured_landmark_places_poi_id_key UNIQUE (poi_id);


--
-- Name: guidance_point_images guidance_point_images_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_point_images
    ADD CONSTRAINT guidance_point_images_pkey PRIMARY KEY (id);


--
-- Name: guidance_point_images guidance_point_images_sort_uq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_point_images
    ADD CONSTRAINT guidance_point_images_sort_uq UNIQUE (point_id, sort_order);


--
-- Name: guidance_points guidance_points_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_points
    ADD CONSTRAINT guidance_points_pkey PRIMARY KEY (id);


--
-- Name: i18n_texts i18n_texts_entity_table_entity_id_field_lang_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_texts
    ADD CONSTRAINT i18n_texts_entity_table_entity_id_field_lang_key UNIQUE (entity_table, entity_id, field, lang);


--
-- Name: i18n_texts i18n_texts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_texts
    ADD CONSTRAINT i18n_texts_pkey PRIMARY KEY (id);


--
-- Name: instruction_templates instruction_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.instruction_templates
    ADD CONSTRAINT instruction_templates_pkey PRIMARY KEY (id);


--
-- Name: instruction_templates instruction_templates_template_key_lang_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.instruction_templates
    ADD CONSTRAINT instruction_templates_template_key_lang_key UNIQUE (template_key, lang);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: languages languages_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.languages
    ADD CONSTRAINT languages_code_key UNIQUE (code);


--
-- Name: languages languages_locale_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.languages
    ADD CONSTRAINT languages_locale_key UNIQUE (locale);


--
-- Name: languages languages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.languages
    ADD CONSTRAINT languages_pkey PRIMARY KEY (id);


--
-- Name: mesh_adjacency mesh_adjacency_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_adjacency
    ADD CONSTRAINT mesh_adjacency_pkey PRIMARY KEY (id);


--
-- Name: mesh_triangles mesh_triangles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_triangles
    ADD CONSTRAINT mesh_triangles_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: page_faqs page_faqs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.page_faqs
    ADD CONSTRAINT page_faqs_pkey PRIMARY KEY (id);


--
-- Name: pages pages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pages
    ADD CONSTRAINT pages_pkey PRIMARY KEY (id);


--
-- Name: pages pages_type_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pages
    ADD CONSTRAINT pages_type_key UNIQUE (type);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: poi_points poi_points_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.poi_points
    ADD CONSTRAINT poi_points_pkey PRIMARY KEY (id);


--
-- Name: prayer_times prayer_times_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prayer_times
    ADD CONSTRAINT prayer_times_pkey PRIMARY KEY (d_greg);


--
-- Name: qrcodes qrcodes_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qrcodes
    ADD CONSTRAINT qrcodes_code_key UNIQUE (code);


--
-- Name: qrcodes qrcodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qrcodes
    ADD CONSTRAINT qrcodes_pkey PRIMARY KEY (id);


--
-- Name: route_logs_debug route_logs_debug_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.route_logs_debug
    ADD CONSTRAINT route_logs_debug_pkey PRIMARY KEY (id);


--
-- Name: route_logs route_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.route_logs
    ADD CONSTRAINT route_logs_pkey PRIMARY KEY (id);


--
-- Name: routing_edge_areas routing_edge_areas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.routing_edge_areas
    ADD CONSTRAINT routing_edge_areas_pkey PRIMARY KEY (edge_id, area_id);


--
-- Name: routing_edges_static routing_edges_static_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.routing_edges_static
    ADD CONSTRAINT routing_edges_static_pkey PRIMARY KEY (id);


--
-- Name: routing_nodes routing_nodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.routing_nodes
    ADD CONSTRAINT routing_nodes_pkey PRIMARY KEY (id);


--
-- Name: routing_walkable_cache routing_walkable_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.routing_walkable_cache
    ADD CONSTRAINT routing_walkable_cache_pkey PRIMARY KEY (floor, gender, mode, ts_bucket);


--
-- Name: rule_sets rule_sets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rule_sets
    ADD CONSTRAINT rule_sets_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: support_feedbacks support_feedbacks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_feedbacks
    ADD CONSTRAINT support_feedbacks_pkey PRIMARY KEY (id);


--
-- Name: temp_block_areas_live temp_block_areas_live_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.temp_block_areas_live
    ADD CONSTRAINT temp_block_areas_live_pkey PRIMARY KEY (id);


--
-- Name: user_feedbacks user_feedbacks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_feedbacks
    ADD CONSTRAINT user_feedbacks_pkey PRIMARY KEY (id);


--
-- Name: user_login_logs user_login_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_login_logs
    ADD CONSTRAINT user_login_logs_pkey PRIMARY KEY (id);


--
-- Name: user_refresh_tokens user_refresh_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_refresh_tokens
    ADD CONSTRAINT user_refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: user_refresh_tokens user_refresh_tokens_token_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_refresh_tokens
    ADD CONSTRAINT user_refresh_tokens_token_key UNIQUE (token);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: van_edges van_edges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.van_edges
    ADD CONSTRAINT van_edges_pkey PRIMARY KEY (id);


--
-- Name: van_nodes van_nodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.van_nodes
    ADD CONSTRAINT van_nodes_pkey PRIMARY KEY (id);


--
-- Name: access_prayer_restrictions_entity_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX access_prayer_restrictions_entity_idx ON public.access_prayer_restrictions USING btree (entity_table, entity_id);


--
-- Name: access_prayer_restrictions_event_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX access_prayer_restrictions_event_idx ON public.access_prayer_restrictions USING btree (prayer_event);


--
-- Name: access_time_restrictions_date_time_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX access_time_restrictions_date_time_idx ON public.access_time_restrictions USING btree (specific_date, start_time, end_time);


--
-- Name: access_time_restrictions_entity_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX access_time_restrictions_entity_idx ON public.access_time_restrictions USING btree (entity_table, entity_id);


--
-- Name: admin_activity_logs_action_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_activity_logs_action_idx ON public.admin_activity_logs USING btree (action);


--
-- Name: admin_activity_logs_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_activity_logs_created_idx ON public.admin_activity_logs USING btree (created_at DESC);


--
-- Name: admin_activity_logs_entity_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_activity_logs_entity_idx ON public.admin_activity_logs USING btree (entity_table, entity_id);


--
-- Name: admin_activity_logs_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_activity_logs_user_idx ON public.admin_activity_logs USING btree (user_id, created_at);


--
-- Name: admin_auth_mfa_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_auth_mfa_idx ON public.admin_auth USING btree (mfa_enabled);


--
-- Name: admin_login_logs_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_login_logs_created_idx ON public.admin_login_logs USING btree (created_at DESC);


--
-- Name: admin_login_logs_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_login_logs_user_idx ON public.admin_login_logs USING btree (user_id, created_at DESC);


--
-- Name: admin_refresh_tokens_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_refresh_tokens_user_idx ON public.admin_refresh_tokens USING btree (user_id);


--
-- Name: admin_refresh_tokens_valid_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_refresh_tokens_valid_idx ON public.admin_refresh_tokens USING btree (user_id, expires_at) WHERE (revoked_at IS NULL);


--
-- Name: admin_restrictions_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_restrictions_floor_idx ON public.admin_restrictions USING btree (floor);


--
-- Name: admin_restrictions_geom_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_restrictions_geom_gix ON public.admin_restrictions USING gist (geom);


--
-- Name: admin_restrictions_target_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_restrictions_target_idx ON public.admin_restrictions USING btree (target_table, target_id);


--
-- Name: admin_restrictions_time_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_restrictions_time_idx ON public.admin_restrictions USING btree (is_active, starts_at, ends_at);


--
-- Name: areas_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX areas_floor_idx ON public.areas USING btree (floor);


--
-- Name: areas_geom_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX areas_geom_gist ON public.areas USING gist (geom);


--
-- Name: categories_code_prop_parent_uq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX categories_code_prop_parent_uq ON public.categories USING btree (code, property_target, COALESCE(parent_id, (0)::bigint));


--
-- Name: categories_parent_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX categories_parent_idx ON public.categories USING btree (parent_id);


--
-- Name: categories_prop_target_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX categories_prop_target_idx ON public.categories USING btree (property_target);


--
-- Name: contents_lang_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX contents_lang_idx ON public.contents USING btree (lang);


--
-- Name: door_access_points_door_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX door_access_points_door_floor_idx ON public.door_access_points USING btree (door_id, floor);


--
-- Name: door_access_points_door_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX door_access_points_door_idx ON public.door_access_points USING btree (door_id);


--
-- Name: door_access_points_from_area_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX door_access_points_from_area_idx ON public.door_access_points USING btree (from_area) WHERE (from_area IS NOT NULL);


--
-- Name: door_access_points_from_to_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX door_access_points_from_to_idx ON public.door_access_points USING btree (from_area, to_area);


--
-- Name: door_access_points_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX door_access_points_gix ON public.door_access_points USING gist (geom);


--
-- Name: door_access_points_review_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX door_access_points_review_idx ON public.door_access_points USING btree (needs_review, floor);


--
-- Name: door_access_points_to_area_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX door_access_points_to_area_idx ON public.door_access_points USING btree (to_area) WHERE (to_area IS NOT NULL);


--
-- Name: door_schedules_door_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX door_schedules_door_idx ON public.door_schedules USING btree (door_id, dow);


--
-- Name: doors_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX doors_floor_idx ON public.doors USING btree (floor);


--
-- Name: doors_from_to_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX doors_from_to_idx ON public.doors USING btree (from_area, to_area);


--
-- Name: doors_geom_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX doors_geom_gix ON public.doors USING gist (geom);


--
-- Name: doors_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX doors_gix ON public.doors USING gist (geom);


--
-- Name: feature_group_mappings_cat_leaf_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX feature_group_mappings_cat_leaf_idx ON public.feature_group_mappings USING btree (category_leaf_id);


--
-- Name: feature_group_mappings_uq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX feature_group_mappings_uq ON public.feature_group_mappings USING btree (entity_table, feature_key);


--
-- Name: featured_landmark_places_active_sort_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX featured_landmark_places_active_sort_idx ON public.featured_landmark_places USING btree (is_active, sort_order, id);


--
-- Name: guidance_point_images_heading_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_point_images_heading_idx ON public.guidance_point_images USING btree (point_id, azimuth_deg);


--
-- Name: guidance_point_images_point_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_point_images_point_id_idx ON public.guidance_point_images USING btree (point_id);


--
-- Name: guidance_point_images_point_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_point_images_point_idx ON public.guidance_point_images USING btree (point_id);


--
-- Name: guidance_point_images_sort_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_point_images_sort_idx ON public.guidance_point_images USING btree (point_id, sort_order);


--
-- Name: guidance_points_area_sort_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_points_area_sort_idx ON public.guidance_points USING btree (floor, area_id, sort_order);


--
-- Name: guidance_points_created_by_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_points_created_by_idx ON public.guidance_points USING btree (created_by);


--
-- Name: guidance_points_geom_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_points_geom_gix ON public.guidance_points USING gist (geom);


--
-- Name: guidance_points_public_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_points_public_idx ON public.guidance_points USING btree (floor, is_active, deleted_at);


--
-- Name: guidance_points_updated_by_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guidance_points_updated_by_idx ON public.guidance_points USING btree (updated_by);


--
-- Name: i18n_entity_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX i18n_entity_idx ON public.i18n_texts USING btree (entity_table, entity_id);


--
-- Name: i18n_lang_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX i18n_lang_idx ON public.i18n_texts USING btree (lang);


--
-- Name: idx_areas_floor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_areas_floor ON public.areas USING btree (floor);


--
-- Name: idx_destinations_geom; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_destinations_geom ON public.destinations USING gist (geom);


--
-- Name: idx_destinations_tags_gin; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_destinations_tags_gin ON public.destinations USING gin (tags);


--
-- Name: idx_destinations_user_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_destinations_user_source ON public.destinations USING btree (user_id, source);


--
-- Name: idx_destinations_user_updated; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_destinations_user_updated ON public.destinations USING btree (user_id, updated_at DESC);


--
-- Name: idx_edges_floor_dst; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_edges_floor_dst ON public.routing_edges_static USING btree (floor, dst);


--
-- Name: idx_edges_floor_src; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_edges_floor_src ON public.routing_edges_static USING btree (floor, src);


--
-- Name: idx_i18n_poi_title; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_i18n_poi_title ON public.i18n_texts USING btree (entity_table, entity_id, field, lang);


--
-- Name: idx_mesh_adjacency_tri_a; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mesh_adjacency_tri_a ON public.mesh_adjacency USING btree (tri_a);


--
-- Name: idx_mesh_adjacency_tri_b; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mesh_adjacency_tri_b ON public.mesh_adjacency USING btree (tri_b);


--
-- Name: idx_mesh_triangles_area; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mesh_triangles_area ON public.mesh_triangles USING btree (area_id);


--
-- Name: idx_mesh_triangles_floor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mesh_triangles_floor ON public.mesh_triangles USING btree (floor);


--
-- Name: idx_mesh_triangles_geom_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_mesh_triangles_geom_gist ON public.mesh_triangles USING gist (geom);


--
-- Name: idx_nodes_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_nodes_id ON public.routing_nodes USING btree (id);


--
-- Name: idx_rea_area; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rea_area ON public.routing_edge_areas USING btree (area_id);


--
-- Name: idx_rea_edge; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rea_edge ON public.routing_edge_areas USING btree (edge_id);


--
-- Name: idx_routing_nodes_ref; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_routing_nodes_ref ON public.routing_nodes USING btree (ref_table, ref_id);


--
-- Name: idx_routing_walkable_cache_geom; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_routing_walkable_cache_geom ON public.routing_walkable_cache USING gist (geom);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: mesh_adjacency_gate_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_adjacency_gate_gix ON public.mesh_adjacency USING gist (gate_point);


--
-- Name: mesh_adjacency_pair_uidx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX mesh_adjacency_pair_uidx ON public.mesh_adjacency USING btree (tri_a, tri_b);


--
-- Name: mesh_adjacency_tri_a_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_adjacency_tri_a_idx ON public.mesh_adjacency USING btree (tri_a);


--
-- Name: mesh_adjacency_tri_b_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_adjacency_tri_b_idx ON public.mesh_adjacency USING btree (tri_b);


--
-- Name: mesh_triangles_floor_area_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_triangles_floor_area_gix ON public.mesh_triangles USING gist (geom);


--
-- Name: mesh_triangles_floor_area_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mesh_triangles_floor_area_idx ON public.mesh_triangles USING btree (floor, area_id);


--
-- Name: mv_area_door_stats_area_id_uq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX mv_area_door_stats_area_id_uq ON public.mv_area_door_stats USING btree (area_id);


--
-- Name: mv_area_door_stats_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mv_area_door_stats_floor_idx ON public.mv_area_door_stats USING btree (floor);


--
-- Name: page_faqs_active_sort_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX page_faqs_active_sort_idx ON public.page_faqs USING btree (is_active, sort_order, id);


--
-- Name: pages_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pages_type_idx ON public.pages USING btree (type);


--
-- Name: poi_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX poi_floor_idx ON public.poi_points USING btree (floor);


--
-- Name: poi_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX poi_gix ON public.poi_points USING gist (geom);


--
-- Name: poi_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX poi_type_idx ON public.poi_points USING btree (poi_type);


--
-- Name: qrcodes_active_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX qrcodes_active_idx ON public.qrcodes USING btree (is_active);


--
-- Name: qrcodes_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX qrcodes_gix ON public.qrcodes USING gist (geom);


--
-- Name: route_logs_debug_created_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX route_logs_debug_created_idx ON public.route_logs_debug USING btree (created_at DESC);


--
-- Name: route_logs_ok_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX route_logs_ok_idx ON public.route_logs USING btree (ok);


--
-- Name: route_logs_ts_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX route_logs_ts_idx ON public.route_logs USING btree (ts);


--
-- Name: routing_edges_static_door_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_door_idx ON public.routing_edges_static USING btree (door_id) WHERE (door_id IS NOT NULL);


--
-- Name: routing_edges_static_dst_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_dst_idx ON public.routing_edges_static USING btree (dst);


--
-- Name: routing_edges_static_floor_area_safety_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_area_safety_idx ON public.routing_edges_static USING btree (floor) WHERE ((COALESCE((attrs ->> 'safety'::text), ''::text) <> 'unsafe_direct_fallback'::text) AND (COALESCE((attrs ->> 'valid_inside_area'::text), 'true'::text) = 'true'::text));


--
-- Name: routing_edges_static_floor_bix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_bix ON public.routing_edges_static USING btree (floor);


--
-- Name: routing_edges_static_floor_door_fast_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_door_fast_idx ON public.routing_edges_static USING btree (floor, door_id) WHERE (door_id IS NOT NULL);


--
-- Name: routing_edges_static_floor_dst_fast_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_dst_fast_idx ON public.routing_edges_static USING btree (floor, dst) WHERE ((COALESCE((attrs ->> 'safety'::text), ''::text) <> 'unsafe_direct_fallback'::text) AND (COALESCE((attrs ->> 'valid_inside_area'::text), 'true'::text) = 'true'::text));


--
-- Name: routing_edges_static_floor_dst_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_dst_idx ON public.routing_edges_static USING btree (floor, dst);


--
-- Name: routing_edges_static_floor_geom_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_geom_gix ON public.routing_edges_static USING gist (geom);


--
-- Name: routing_edges_static_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_idx ON public.routing_edges_static USING btree (floor);


--
-- Name: routing_edges_static_floor_src_fast_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_src_fast_idx ON public.routing_edges_static USING btree (floor, src) WHERE ((COALESCE((attrs ->> 'safety'::text), ''::text) <> 'unsafe_direct_fallback'::text) AND (COALESCE((attrs ->> 'valid_inside_area'::text), 'true'::text) = 'true'::text));


--
-- Name: routing_edges_static_floor_src_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_floor_src_idx ON public.routing_edges_static USING btree (floor, src);


--
-- Name: routing_edges_static_geom_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_geom_gist ON public.routing_edges_static USING gist (geom);


--
-- Name: routing_edges_static_src_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_edges_static_src_idx ON public.routing_edges_static USING btree (src);


--
-- Name: routing_nodes_floor_area_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_nodes_floor_area_idx ON public.routing_nodes USING btree (floor, area_id);


--
-- Name: routing_nodes_floor_area_ref_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_nodes_floor_area_ref_idx ON public.routing_nodes USING btree (floor, area_id, ref_table, ref_id);


--
-- Name: routing_nodes_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_nodes_floor_idx ON public.routing_nodes USING btree (floor);


--
-- Name: routing_nodes_geom_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_nodes_geom_gist ON public.routing_nodes USING gist (geom);


--
-- Name: routing_nodes_ref_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX routing_nodes_ref_idx ON public.routing_nodes USING btree (ref_table, ref_id, floor);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: support_feedbacks_created_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX support_feedbacks_created_at_idx ON public.support_feedbacks USING btree (created_at DESC);


--
-- Name: support_feedbacks_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX support_feedbacks_status_idx ON public.support_feedbacks USING btree (status);


--
-- Name: support_feedbacks_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX support_feedbacks_user_idx ON public.support_feedbacks USING btree (user_id);


--
-- Name: temp_block_areas_live_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX temp_block_areas_live_floor_idx ON public.temp_block_areas_live USING btree (floor);


--
-- Name: temp_block_areas_live_geom_gist; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX temp_block_areas_live_geom_gist ON public.temp_block_areas_live USING gist (geom);


--
-- Name: temp_block_areas_live_time_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX temp_block_areas_live_time_idx ON public.temp_block_areas_live USING btree (valid_from, valid_to);


--
-- Name: user_feedbacks_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_feedbacks_status_idx ON public.user_feedbacks USING btree (status);


--
-- Name: user_feedbacks_target_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_feedbacks_target_status_idx ON public.user_feedbacks USING btree (target_type, target_id, status);


--
-- Name: user_feedbacks_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_feedbacks_user_idx ON public.user_feedbacks USING btree (user_id);


--
-- Name: user_feedbacks_user_target_uq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX user_feedbacks_user_target_uq ON public.user_feedbacks USING btree (user_id, target_type, target_id, lang);


--
-- Name: user_login_logs_created_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_login_logs_created_at_idx ON public.user_login_logs USING btree (created_at);


--
-- Name: user_login_logs_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_login_logs_user_idx ON public.user_login_logs USING btree (user_id);


--
-- Name: user_refresh_tokens_user_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_refresh_tokens_user_idx ON public.user_refresh_tokens USING btree (user_id);


--
-- Name: user_refresh_tokens_valid_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_refresh_tokens_valid_idx ON public.user_refresh_tokens USING btree (user_id, expires_at) WHERE (revoked_at IS NULL);


--
-- Name: users_is_admin_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_is_admin_idx ON public.users USING btree (is_admin);


--
-- Name: users_mobile_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_mobile_unique ON public.users USING btree (mobile) WHERE (mobile IS NOT NULL);


--
-- Name: users_referral_code_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_referral_code_unique ON public.users USING btree (referral_code) WHERE (referral_code IS NOT NULL);


--
-- Name: users_username_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_username_unique ON public.users USING btree (username) WHERE (username IS NOT NULL);


--
-- Name: van_edges_dst_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX van_edges_dst_idx ON public.van_edges USING btree (dst);


--
-- Name: van_edges_open_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX van_edges_open_idx ON public.van_edges USING btree (is_open);


--
-- Name: van_edges_src_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX van_edges_src_idx ON public.van_edges USING btree (src);


--
-- Name: van_nodes_floor_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX van_nodes_floor_idx ON public.van_nodes USING btree (floor);


--
-- Name: van_nodes_gix; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX van_nodes_gix ON public.van_nodes USING gist (geom);


--
-- Name: page_faqs page_faqs_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER page_faqs_set_updated_at BEFORE UPDATE ON public.page_faqs FOR EACH ROW EXECUTE FUNCTION public.trg_set_updated_at();


--
-- Name: pages pages_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER pages_set_updated_at BEFORE UPDATE ON public.pages FOR EACH ROW EXECUTE FUNCTION public.trg_set_updated_at();


--
-- Name: admin_restrictions trg_ar_touch_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_ar_touch_updated_at BEFORE UPDATE ON public.admin_restrictions FOR EACH ROW EXECUTE FUNCTION public._ar_touch_updated_at();


--
-- Name: destinations trg_destinations_geom; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_destinations_geom BEFORE INSERT OR UPDATE OF x, y ON public.destinations FOR EACH ROW EXECUTE FUNCTION public.trg_destinations_sync_geom();


--
-- Name: destinations trg_destinations_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_destinations_updated_at BEFORE UPDATE ON public.destinations FOR EACH ROW EXECUTE FUNCTION public.trg_destinations_set_updated_at();


--
-- Name: van_edges trg_van_edges_no_self_loop; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_van_edges_no_self_loop BEFORE INSERT OR UPDATE ON public.van_edges FOR EACH ROW EXECUTE FUNCTION public._van_edge_no_self_loop();


--
-- Name: user_feedbacks user_feedbacks_after_change; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER user_feedbacks_after_change AFTER INSERT OR DELETE OR UPDATE ON public.user_feedbacks FOR EACH ROW EXECUTE FUNCTION public.trg_user_feedbacks_after_change();


--
-- Name: admin_auth admin_auth_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_auth
    ADD CONSTRAINT admin_auth_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: admin_login_logs admin_login_logs_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_login_logs
    ADD CONSTRAINT admin_login_logs_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: admin_refresh_tokens admin_refresh_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_refresh_tokens
    ADD CONSTRAINT admin_refresh_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: admin_role_permissions admin_role_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_role_permissions
    ADD CONSTRAINT admin_role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.admin_permissions(id) ON DELETE CASCADE;


--
-- Name: admin_role_permissions admin_role_permissions_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_role_permissions
    ADD CONSTRAINT admin_role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.admin_roles(id) ON DELETE CASCADE;


--
-- Name: admin_user_roles admin_user_roles_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_user_roles
    ADD CONSTRAINT admin_user_roles_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.admin_roles(id) ON DELETE RESTRICT;


--
-- Name: admin_user_roles admin_user_roles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_user_roles
    ADD CONSTRAINT admin_user_roles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: areas_simplified areas_simplified_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.areas_simplified
    ADD CONSTRAINT areas_simplified_id_fkey FOREIGN KEY (id) REFERENCES public.areas(id) ON DELETE CASCADE;


--
-- Name: categories categories_parent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES public.categories(id) ON DELETE RESTRICT;


--
-- Name: contents contents_poi_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contents
    ADD CONSTRAINT contents_poi_id_fkey FOREIGN KEY (poi_id) REFERENCES public.poi_points(id) ON DELETE CASCADE;


--
-- Name: destinations destinations_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.destinations
    ADD CONSTRAINT destinations_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: door_access_points door_access_points_door_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.door_access_points
    ADD CONSTRAINT door_access_points_door_id_fkey FOREIGN KEY (door_id) REFERENCES public.doors(id) ON DELETE CASCADE;


--
-- Name: door_access_points door_access_points_from_area_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.door_access_points
    ADD CONSTRAINT door_access_points_from_area_fkey FOREIGN KEY (from_area) REFERENCES public.areas(id) ON DELETE RESTRICT;


--
-- Name: door_access_points door_access_points_to_area_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.door_access_points
    ADD CONSTRAINT door_access_points_to_area_fkey FOREIGN KEY (to_area) REFERENCES public.areas(id) ON DELETE RESTRICT;


--
-- Name: doors doors_from_area_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doors
    ADD CONSTRAINT doors_from_area_fkey FOREIGN KEY (from_area) REFERENCES public.areas(id) ON DELETE RESTRICT;


--
-- Name: doors doors_to_area_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.doors
    ADD CONSTRAINT doors_to_area_fkey FOREIGN KEY (to_area) REFERENCES public.areas(id) ON DELETE RESTRICT;


--
-- Name: feature_group_mappings feature_group_mappings_category_leaf_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.feature_group_mappings
    ADD CONSTRAINT feature_group_mappings_category_leaf_fk FOREIGN KEY (category_leaf_id) REFERENCES public.categories(id);


--
-- Name: featured_landmark_places featured_landmark_places_poi_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.featured_landmark_places
    ADD CONSTRAINT featured_landmark_places_poi_id_fkey FOREIGN KEY (poi_id) REFERENCES public.poi_points(id) ON DELETE CASCADE;


--
-- Name: guidance_point_images guidance_point_images_point_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guidance_point_images
    ADD CONSTRAINT guidance_point_images_point_id_foreign FOREIGN KEY (point_id) REFERENCES public.guidance_points(id) ON DELETE CASCADE;


--
-- Name: mesh_adjacency mesh_adjacency_door_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_adjacency
    ADD CONSTRAINT mesh_adjacency_door_id_fkey FOREIGN KEY (door_id) REFERENCES public.doors(id) ON DELETE SET NULL;


--
-- Name: mesh_adjacency mesh_adjacency_tri_a_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_adjacency
    ADD CONSTRAINT mesh_adjacency_tri_a_fkey FOREIGN KEY (tri_a) REFERENCES public.mesh_triangles(id) ON DELETE CASCADE;


--
-- Name: mesh_adjacency mesh_adjacency_tri_b_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_adjacency
    ADD CONSTRAINT mesh_adjacency_tri_b_fkey FOREIGN KEY (tri_b) REFERENCES public.mesh_triangles(id) ON DELETE CASCADE;


--
-- Name: mesh_triangles mesh_triangles_area_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mesh_triangles
    ADD CONSTRAINT mesh_triangles_area_id_fkey FOREIGN KEY (area_id) REFERENCES public.areas(id) ON DELETE SET NULL;


--
-- Name: routing_edges_static routing_edges_static_dst_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.routing_edges_static
    ADD CONSTRAINT routing_edges_static_dst_fkey FOREIGN KEY (dst) REFERENCES public.routing_nodes(id);


--
-- Name: routing_edges_static routing_edges_static_src_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.routing_edges_static
    ADD CONSTRAINT routing_edges_static_src_fkey FOREIGN KEY (src) REFERENCES public.routing_nodes(id);


--
-- Name: support_feedbacks support_feedbacks_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.support_feedbacks
    ADD CONSTRAINT support_feedbacks_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: user_feedbacks user_feedbacks_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_feedbacks
    ADD CONSTRAINT user_feedbacks_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- Name: user_feedbacks user_feedbacks_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_feedbacks
    ADD CONSTRAINT user_feedbacks_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: user_login_logs user_login_logs_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_login_logs
    ADD CONSTRAINT user_login_logs_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: user_refresh_tokens user_refresh_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_refresh_tokens
    ADD CONSTRAINT user_refresh_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: van_edges van_edges_dst_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.van_edges
    ADD CONSTRAINT van_edges_dst_fkey FOREIGN KEY (dst) REFERENCES public.van_nodes(id) ON DELETE CASCADE;


--
-- Name: van_edges van_edges_src_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.van_edges
    ADD CONSTRAINT van_edges_src_fkey FOREIGN KEY (src) REFERENCES public.van_nodes(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

