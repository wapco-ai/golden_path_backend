-- Purpose: restore meaningful alternatives without changing the primary route.
-- Dependencies: 20260915 multifloor connector and routing functions.
-- Objects: fn_route_multifloor_edges; no graph tables or stored geometry change.
-- Routing: DAP-derived edges, access rules, direction and transfer timing are preserved.
-- Policy: 1.5 m tolerance, 85% middle overlap, 4 m deviation; at most 75% extra cost.
-- Rollback: apply the paired down.sql to restore the previous function; no data removal.
BEGIN;
CREATE OR REPLACE FUNCTION public.fn_route_multifloor_edges(
  p_now timestamptz, p_gender public.gender_enum, p_mode text,
  p_origin_floor smallint, p_dest_floor smallint,
  p_origin geometry, p_dest geometry, p_k integer DEFAULT 1
) RETURNS TABLE (
  path_id integer, path_seq integer, from_node bigint, to_node bigint,
  edge_id bigint, door_id bigint, from_floor smallint, to_floor smallint,
  connector_id bigint, connector_kind text, geometry_json text,
  from_point text, to_point text, distance_m float8, duration_s float8, route_cost float8
) LANGUAGE plpgsql AS $$
DECLARE
  origin_area bigint; dest_area bigint;
  requested integer := LEAST(GREATEST(COALESCE(p_k,1),1),4);
  candidate_limit integer;
  attempt integer; selected_count integer := 0; changed_count integer;
  candidate_cost float8; main_cost float8; walk_length float8;
  v_transfers jsonb; too_similar boolean;
  speed float8 := CASE p_mode WHEN 'wheelchair' THEN 1.0 WHEN 'van' THEN 5.0 ELSE 1.2 END;
BEGIN
  origin_area := fn_route_point_area_id(p_now,p_gender,p_mode,p_origin_floor,p_origin);
  dest_area := fn_route_point_area_id(p_now,p_gender,p_mode,p_dest_floor,p_dest);
  IF origin_area IS NULL OR dest_area IS NULL THEN RETURN; END IF;

  DROP TABLE IF EXISTS pg_temp.mf_edges;
  CREATE TEMP TABLE mf_edges (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source bigint, target bigint, cost float8, reverse_cost float8,
    ref_edge bigint, door_ref bigint, floor_a smallint, floor_b smallint,
    connector_ref bigint, kind text, geom geometry, a geometry, b geometry,
    duration float8, reverse_duration float8, distance float8,
    penalty_w float8 NOT NULL DEFAULT 1.0
  ) ON COMMIT DROP;

  INSERT INTO mf_edges(source,target,cost,reverse_cost,ref_edge,door_ref,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT e.src,e.dst,e.cost/speed,CASE WHEN e.reverse_cost<0 THEN -1 ELSE e.reverse_cost/speed END,
    e.id,e.door_id,f.floor,f.floor,e.geom,ST_StartPoint(e.geom),ST_EndPoint(e.geom),
    ST_Length(e.geom)/speed,ST_Length(e.geom)/speed,ST_Length(e.geom)
  FROM routing_floors f CROSS JOIN LATERAL fn_routing_edges_live_param(p_now,p_gender,p_mode,f.floor) e
  WHERE (e.cost>0 OR e.reverse_cost>0) AND e.geom IS NOT NULL AND NOT ST_IsEmpty(e.geom);

  -- Physical presence/readiness is checked for the whole connector; access rules
  -- are checked per boarding/alighting stop (a lift may pass a closed intermediate floor).
  WITH ready_connectors AS (
    SELECT c.id FROM routing_connectors c WHERE c.is_active
      AND (SELECT count(*) FROM routing_connector_stops s WHERE s.connector_id=c.id)>=2
      AND NOT EXISTS (
        SELECT 1 FROM routing_connector_stops s JOIN doors d ON d.id=s.door_id
        WHERE s.connector_id=c.id AND (
          COALESCE(d.attrs #>> '{graph,status}','ready') <> 'ready'
          OR NOT EXISTS (SELECT 1 FROM routing_connector_nodes n WHERE n.id=s.id)
        )
      )
  ), valid_stops AS (
    SELECT n.* FROM routing_connector_nodes n JOIN ready_connectors rc ON rc.id=n.connector_id
    JOIN LATERAL fn_allowed_doors(p_now,p_gender,p_mode,n.floor) d ON d.id=n.door_id AND d.is_allowed
    JOIN LATERAL fn_allowed_areas(p_now,p_gender,p_mode,n.floor) a ON a.id=n.area_id AND a.is_allowed
    WHERE p_mode <> 'van' AND (p_mode <> 'wheelchair' OR n.kind NOT IN ('stair','escalator'))
  ), pairs AS (
    SELECT a.node_id AS source,b.node_id AS target,a.connector_id,a.kind,a.direction,a.wait_seconds,
      a.floor AS floor_a,b.floor AS floor_b,a.geom AS a,b.geom AS b,a.position AS pos_a,b.position AS pos_b
    FROM valid_stops a JOIN valid_stops b ON b.connector_id=a.connector_id AND b.position>a.position
    WHERE a.kind='elevator' OR b.position=a.position+1
  ), timed AS (
    SELECT p.*, t.forward_s+p.wait_seconds AS forward_s,t.reverse_s+p.wait_seconds AS reverse_s
    FROM pairs p CROSS JOIN LATERAL (
      SELECT sum(s.travel_seconds)::float8 AS forward_s,
        sum(COALESCE(s.reverse_seconds,s.travel_seconds))::float8 AS reverse_s
      FROM routing_connector_stops s WHERE s.connector_id=p.connector_id
        AND s.position>=p.pos_a AND s.position<p.pos_b
    ) t
  )
  INSERT INTO mf_edges(source,target,cost,reverse_cost,floor_a,floor_b,connector_ref,kind,a,b,duration,reverse_duration,distance)
  SELECT t.source,t.target,CASE WHEN t.direction='reverse' THEN -1 ELSE t.forward_s END,
    CASE WHEN t.direction='forward' THEN -1 ELSE t.reverse_s END,t.floor_a,t.floor_b,t.connector_id,t.kind,t.a,t.b,
    t.forward_s,t.reverse_s,0 FROM timed t;

  -- Every anchor remains confined to its own floor and validated walkable area.
  INSERT INTO mf_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT -1,n.id,GREATEST(ST_Length(g.geom)/speed,0.01),-1,p_origin_floor,p_origin_floor,
    g.geom,p_origin,n.geom,ST_Length(g.geom)/speed,ST_Length(g.geom)/speed,ST_Length(g.geom)
  FROM routing_nodes n CROSS JOIN LATERAL (
    SELECT fn_build_intra_area_edge_geom(origin_area,p_origin_floor,p_origin,n.geom,0.20) AS geom
  ) g WHERE n.floor=p_origin_floor AND n.area_id=origin_area AND n.ref_table='door_access_points'
    AND EXISTS (SELECT 1 FROM mf_edges e WHERE e.source=n.id OR e.target=n.id)
    AND g.geom IS NOT NULL AND fn_route_line_valid_inside_area(origin_area,g.geom,0.20);

  INSERT INTO mf_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT n.id,-2,GREATEST(ST_Length(g.geom)/speed,0.01),-1,p_dest_floor,p_dest_floor,
    g.geom,n.geom,p_dest,ST_Length(g.geom)/speed,ST_Length(g.geom)/speed,ST_Length(g.geom)
  FROM routing_nodes n CROSS JOIN LATERAL (
    SELECT fn_build_intra_area_edge_geom(dest_area,p_dest_floor,n.geom,p_dest,0.20) AS geom
  ) g WHERE n.floor=p_dest_floor AND n.area_id=dest_area AND n.ref_table='door_access_points'
    AND EXISTS (SELECT 1 FROM mf_edges e WHERE e.source=n.id OR e.target=n.id)
    AND g.geom IS NOT NULL AND fn_route_line_valid_inside_area(dest_area,g.geom,0.20);

  IF p_origin_floor=p_dest_floor AND origin_area=dest_area THEN
    INSERT INTO mf_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
    SELECT -1,-2,GREATEST(ST_Length(g)/speed,0.01),-1,p_origin_floor,p_dest_floor,g,p_origin,p_dest,
      ST_Length(g)/speed,ST_Length(g)/speed,ST_Length(g)
    FROM (SELECT fn_build_intra_area_edge_geom(origin_area,p_origin_floor,p_origin,p_dest,0.20) AS g) direct
    WHERE g IS NOT NULL AND fn_route_line_valid_inside_area(origin_area,g,0.20);
  END IF;
  CREATE INDEX ON mf_edges(source);
  CREATE INDEX ON mf_edges(target);

  -- Build the live graph once. Search further candidates by penalizing shared
  -- middle edges, rather than returning the first almost-identical KSP paths.
  candidate_limit := CASE WHEN requested=1 THEN 1 ELSE LEAST(requested+2,6) END;
  DROP TABLE IF EXISTS pg_temp.mf_candidate_path;
  DROP TABLE IF EXISTS pg_temp.mf_candidate_shapes;
  DROP TABLE IF EXISTS pg_temp.mf_selected_paths;
  DROP TABLE IF EXISTS pg_temp.mf_selected_routes;
  DROP TABLE IF EXISTS pg_temp.mf_selected_shapes;
  CREATE TEMP TABLE mf_candidate_path (
    path_seq integer, node bigint, edge bigint, distance float8,
    floor smallint, geom geometry, connector_ref bigint, base_cost float8
  ) ON COMMIT DROP;
  CREATE TEMP TABLE mf_candidate_shapes (floor smallint, geom geometry, middle_geom geometry) ON COMMIT DROP;
  CREATE TEMP TABLE mf_selected_paths (path_id integer, path_seq integer, node bigint, edge bigint) ON COMMIT DROP;
  CREATE TEMP TABLE mf_selected_routes (path_id integer, transfers jsonb) ON COMMIT DROP;
  CREATE TEMP TABLE mf_selected_shapes (path_id integer, floor smallint, geom geometry) ON COMMIT DROP;

  FOR attempt IN 1..candidate_limit LOOP
    TRUNCATE mf_candidate_path, mf_candidate_shapes;
    INSERT INTO mf_candidate_path
    SELECT r.path_seq::integer,r.node::bigint,r.edge::bigint,e.distance,
      CASE WHEN r.node=e.source THEN e.floor_a ELSE e.floor_b END,
      CASE WHEN r.node=e.source THEN e.geom ELSE ST_Reverse(e.geom) END,
      e.connector_ref,CASE WHEN r.node=e.source THEN e.cost ELSE e.reverse_cost END
    FROM pgr_ksp(
      'SELECT id,source,target,cost*penalty_w AS cost,reverse_cost*penalty_w AS reverse_cost FROM pg_temp.mf_edges',
      -1::bigint,-2::bigint,1,directed := true
    ) r JOIN mf_edges e ON e.id=r.edge;
    IF NOT FOUND THEN EXIT; END IF;
    SELECT sum(c.base_cost),sum(c.distance) INTO candidate_cost,walk_length FROM mf_candidate_path c;
    IF attempt=1 THEN main_cost := candidate_cost; END IF;

    -- Consecutive hops on the same lift are one transfer choice, not an alternative.
    SELECT COALESCE(jsonb_agg(t.connector_ref ORDER BY t.path_seq),'[]'::jsonb)
    INTO v_transfers FROM (
      SELECT c.path_seq,c.connector_ref,lag(c.connector_ref) OVER (ORDER BY c.path_seq) AS previous_ref
      FROM mf_candidate_path c WHERE c.connector_ref IS NOT NULL
    ) t WHERE t.connector_ref IS DISTINCT FROM t.previous_ref;

    WITH walked AS (
      SELECT c.*,sum(c.distance) OVER (ORDER BY c.path_seq)-c.distance AS distance_before
      FROM mf_candidate_path c WHERE c.geom IS NOT NULL AND c.distance>0
    ), clipped AS (
      SELECT w.*,
        GREATEST(0.0,(walk_length*0.15-w.distance_before)/w.distance) AS lo,
        LEAST(1.0,(walk_length*0.85-w.distance_before)/w.distance) AS hi
      FROM walked w
    )
    INSERT INTO mf_candidate_shapes
    SELECT c.floor,ST_Collect(c.geom),
      ST_Collect(CASE WHEN c.lo<c.hi THEN ST_LineSubstring(c.geom,c.lo,c.hi) END)
    FROM clipped c GROUP BY c.floor;

    -- Preserve the original fastest path, then require distinct walking corridors
    -- (on the same floor) or a different physical transfer. No duplicate fallback.
    SELECT EXISTS (
      SELECT 1 FROM mf_selected_routes s WHERE s.transfers=v_transfers AND (
        COALESCE((
          SELECT sum(ST_Length(ST_Intersection(c.middle_geom,ST_Buffer(q.geom,1.5))))
            /NULLIF(sum(ST_Length(c.middle_geom)),0)
          FROM mf_candidate_shapes c LEFT JOIN mf_selected_shapes q
            ON q.path_id=s.path_id AND q.floor=c.floor
        ),1.0)>0.85
        OR COALESCE((
          SELECT max(ST_HausdorffDistance(c.geom,q.geom))
          FROM mf_candidate_shapes c JOIN mf_selected_shapes q
            ON q.path_id=s.path_id AND q.floor=c.floor
        ),0.0)<4.0
      )
    ) INTO too_similar;
    IF attempt=1 OR (NOT too_similar AND candidate_cost<=main_cost*1.75) THEN
      selected_count := selected_count+1;
      INSERT INTO mf_selected_paths SELECT selected_count,c.path_seq,c.node,c.edge FROM mf_candidate_path c;
      INSERT INTO mf_selected_routes VALUES (selected_count,v_transfers);
      INSERT INTO mf_selected_shapes SELECT selected_count,c.floor,c.geom FROM mf_candidate_shapes c;
      IF selected_count>=requested THEN EXIT; END IF;
    END IF;

    -- Endpoints and their floor/area anchors stay unchanged. Only search weights
    -- change; returned geometry, real duration and live access costs are untouched.
    WITH positioned AS (
      SELECT c.edge,c.connector_ref,c.distance,
        sum(c.distance) OVER (ORDER BY c.path_seq)-c.distance AS distance_before
      FROM mf_candidate_path c
    )
    UPDATE mf_edges e SET penalty_w=LEAST(e.penalty_w*50.0,100000000.0)
    WHERE e.source NOT IN (-1,-2) AND e.target NOT IN (-1,-2) AND (
      EXISTS (SELECT 1 FROM positioned p WHERE p.edge=e.id AND
        (p.connector_ref IS NOT NULL
          OR (p.distance_before+p.distance>walk_length*0.15 AND p.distance_before<walk_length*0.85)))
      OR (e.connector_ref IS NOT NULL AND EXISTS (
        SELECT 1 FROM mf_candidate_path c WHERE c.connector_ref=e.connector_ref
      ))
      OR EXISTS (
        SELECT 1 FROM mf_candidate_shapes c WHERE e.floor_a=e.floor_b AND c.floor=e.floor_a
          AND e.geom && ST_Expand(c.middle_geom,1.5) AND ST_Length(e.geom)>0
          AND ST_Length(ST_Intersection(e.geom,ST_Buffer(c.middle_geom,1.5)))/NULLIF(ST_Length(e.geom),0)>0.85
      )
    );
    GET DIAGNOSTICS changed_count = ROW_COUNT;
    IF changed_count=0 THEN EXIT; END IF;
  END LOOP;

  RETURN QUERY
  SELECT r.path_id,r.path_seq,r.node,
    CASE WHEN r.node=e.source THEN e.target ELSE e.source END,
    e.ref_edge,e.door_ref,
    CASE WHEN r.node=e.source THEN e.floor_a ELSE e.floor_b END,
    CASE WHEN r.node=e.source THEN e.floor_b ELSE e.floor_a END,
    e.connector_ref,e.kind,
    CASE WHEN e.geom IS NULL THEN NULL ELSE ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.geom ELSE ST_Reverse(e.geom) END,4326)) END,
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.a ELSE e.b END,4326)),
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.b ELSE e.a END,4326)),
    e.distance,CASE WHEN r.node=e.source THEN e.duration ELSE e.reverse_duration END,
    CASE WHEN r.node=e.source THEN e.cost ELSE e.reverse_cost END
  FROM mf_selected_paths r JOIN mf_edges e ON e.id=r.edge ORDER BY r.path_id,r.path_seq;
END $$;
COMMIT;
