-- Purpose: route over floor graphs and explicit connector stops in one directed graph.
-- Dependencies: 20260915_160000_multifloor_connectors.up.sql and the routing baseline.
-- Objects: fn_route_multifloor_edges; no historical functions are modified.
-- Routing: geometry comes from routing edges/DAP-derived nodes; transfers stay separate.
-- Rollback: drop this function before removing connector tables.
BEGIN;
CREATE FUNCTION public.fn_route_multifloor_edges(
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
    duration float8, reverse_duration float8, distance float8
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

  RETURN QUERY
  SELECT r.path_id::integer,r.path_seq::integer,r.node::bigint,
    CASE WHEN r.node=e.source THEN e.target ELSE e.source END,
    e.ref_edge,e.door_ref,
    CASE WHEN r.node=e.source THEN e.floor_a ELSE e.floor_b END,
    CASE WHEN r.node=e.source THEN e.floor_b ELSE e.floor_a END,
    e.connector_ref,e.kind,
    CASE WHEN e.geom IS NULL THEN NULL ELSE ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.geom ELSE ST_Reverse(e.geom) END,4326)) END,
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.a ELSE e.b END,4326)),
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.b ELSE e.a END,4326)),
    e.distance,CASE WHEN r.node=e.source THEN e.duration ELSE e.reverse_duration END,r.cost::float8
  FROM pgr_ksp('SELECT id,source,target,cost,reverse_cost FROM pg_temp.mf_edges',-1::bigint,-2::bigint,
    LEAST(GREATEST(p_k,1),4),directed := true) r
  JOIN mf_edges e ON e.id=r.edge ORDER BY r.path_id,r.path_seq;
END $$;
COMMIT;
