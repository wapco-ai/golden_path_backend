-- Purpose: route from a nearby legal point when MPR cannot attach the selected origin.
-- Dependencies: multifloor routing, PostGIS and pgRouting.
-- Object: fn_route_nearby_origin_edges. Route legs use live static edges and DAP anchors.
-- Rollback: drop the new function. No map data or stored graph changes.
BEGIN;
CREATE FUNCTION public.fn_route_nearby_origin_edges(
  p_now timestamptz, p_gender public.gender_enum, p_mode text,
  p_origin_floor smallint, p_dest_floor smallint, p_origin geometry, p_dest geometry
) RETURNS TABLE (
  path_id integer, path_seq integer, from_node bigint, to_node bigint,
  edge_id bigint, door_id bigint, from_floor smallint, to_floor smallint,
  connector_id bigint, connector_kind text, geometry_json text,
  from_point text, to_point text, distance_m float8, duration_s float8, route_cost float8,
  nearby_destination boolean
) LANGUAGE plpgsql AS $$
DECLARE
  origin_area bigint;
  dest_area bigint;
  selected_origin bigint;
  selected_destination bigint;
  origin_candidates bigint[];
  destination_candidates bigint[];
  radius_m CONSTANT float8 := 60.0;
  speed float8 := CASE p_mode WHEN 'wheelchair' THEN 1.0 WHEN 'van' THEN 5.0 ELSE 1.2 END;
  graph_sql CONSTANT text := 'SELECT id,source,target,cost,reverse_cost FROM pg_temp.gp_origin_edges';
  reverse_sql CONSTANT text := 'SELECT id,target AS source,source AS target,cost,reverse_cost FROM pg_temp.gp_origin_edges';
BEGIN
  origin_area := fn_route_point_area_id(p_now,p_gender,p_mode,p_origin_floor,p_origin);
  dest_area := fn_route_point_area_id(p_now,p_gender,p_mode,p_dest_floor,p_dest);
  DROP TABLE IF EXISTS pg_temp.gp_origin_edges;
  CREATE TEMP TABLE gp_origin_edges (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source bigint, target bigint, cost float8, reverse_cost float8,
    ref_edge bigint, door_ref bigint, floor_a smallint, floor_b smallint,
    connector_ref bigint, kind text, geom geometry, a geometry, b geometry,
    duration float8, reverse_duration float8, distance float8
  ) ON COMMIT DROP;

  INSERT INTO gp_origin_edges(source,target,cost,reverse_cost,ref_edge,door_ref,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT e.src,e.dst,e.cost/speed,CASE WHEN e.reverse_cost<0 THEN -1 ELSE e.reverse_cost/speed END,
    e.id,e.door_id,f.floor,f.floor,e.geom,ST_StartPoint(e.geom),ST_EndPoint(e.geom),
    ST_Length(e.geom)/speed,ST_Length(e.geom)/speed,ST_Length(e.geom)
  FROM routing_floors f CROSS JOIN LATERAL fn_routing_edges_live_param(p_now,p_gender,p_mode,f.floor) e
  WHERE (e.cost>0 OR e.reverse_cost>0) AND e.geom IS NOT NULL AND NOT ST_IsEmpty(e.geom)
    AND NOT EXISTS (
      SELECT 1 FROM routing_nodes n JOIN areas a ON a.id=n.area_id
      WHERE n.id IN (e.src,e.dst) AND a.attrs #>> '{access_policy,public_access}'='false'
    );

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
      AND (a.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
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
  INSERT INTO gp_origin_edges(source,target,cost,reverse_cost,floor_a,floor_b,connector_ref,kind,a,b,duration,reverse_duration,distance)
  SELECT t.source,t.target,CASE WHEN t.direction='reverse' THEN -1 ELSE t.forward_s END,
    CASE WHEN t.direction='forward' THEN -1 ELSE t.reverse_s END,t.floor_a,t.floor_b,t.connector_id,t.kind,t.a,t.b,
    t.forward_s,t.reverse_s,0 FROM timed t;

  -- A valid origin stays put even if its component cannot reach this destination.
  IF NOT EXISTS (
    SELECT 1 FROM areas a WHERE a.floor=p_origin_floor
      AND a.attrs #>> '{access_policy,public_access}'='false' AND ST_Contains(a.geom,p_origin)
  ) AND EXISTS (
    SELECT 1 FROM routing_nodes n
    CROSS JOIN LATERAL (
      SELECT fn_build_intra_area_edge_geom(origin_area,p_origin_floor,p_origin,n.geom,0.10) AS geom
    ) g
    WHERE n.area_id=origin_area AND n.floor=p_origin_floor AND n.ref_table='door_access_points'
      AND EXISTS (SELECT 1 FROM gp_origin_edges e WHERE (e.source=n.id AND e.cost>0) OR (e.target=n.id AND e.reverse_cost>0))
      AND g.geom IS NOT NULL AND fn_route_line_valid_inside_area(origin_area,g.geom,0.10)
  ) THEN RETURN; END IF;

  -- Keep the exact destination when one of the nearby starts can reach it.
  INSERT INTO gp_origin_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,distance)
  SELECT n.id,-2,GREATEST(ST_Length(g.geom)/speed,0.01),-1,p_dest_floor,p_dest_floor,
    g.geom,n.geom,p_dest,ST_Length(g.geom)/speed,ST_Length(g.geom)
  FROM routing_nodes n CROSS JOIN LATERAL (
    SELECT fn_build_intra_area_edge_geom(dest_area,p_dest_floor,n.geom,p_dest,0.10) AS geom
  ) g
  WHERE n.area_id=dest_area AND n.floor=p_dest_floor AND n.ref_table='door_access_points'
    AND EXISTS (SELECT 1 FROM gp_origin_edges e WHERE (e.target=n.id AND e.cost>0) OR (e.source=n.id AND e.reverse_cost>0))
    AND g.geom IS NOT NULL AND fn_route_line_valid_inside_area(dest_area,g.geom,0.10)
    AND NOT EXISTS (
      SELECT 1 FROM areas a WHERE a.floor=p_dest_floor
        AND a.attrs #>> '{access_policy,public_access}'='false' AND ST_Contains(a.geom,p_dest)
    );

  DROP TABLE IF EXISTS pg_temp.gp_origin_candidates;
  CREATE TEMP TABLE gp_origin_candidates ON COMMIT DROP AS
  WITH endpoints AS (
    SELECT * FROM (VALUES (true,p_origin_floor,p_origin,origin_area),(false,p_dest_floor,p_dest,dest_area))
      v(is_origin,floor,point,allowed_area)
  ), search AS (
    SELECT v.*, a.id AS area_id,
      CASE WHEN a.id IS DISTINCT FROM v.allowed_area THEN COALESCE(a.geom,v.point)
           WHEN a.attrs #>> '{access_policy,public_access}'='false' THEN a.geom ELSE v.point END AS search_geom
    FROM endpoints v
    LEFT JOIN LATERAL (
      SELECT a.id,a.geom,a.attrs FROM areas a WHERE a.floor=v.floor AND ST_Covers(a.geom,v.point)
      ORDER BY ST_Area(a.geom),a.id LIMIT 1
    ) a ON true
  )
  SELECT -10-row_number() OVER () AS node,v.is_origin,v.floor,e.id AS graph_edge,e.ref_edge,
    c.fraction, p.point, COALESCE(c.preferred,false) AS preferred,
    ST_Distance(p.point,v.search_geom) AS gap, ST_Distance(p.point,v.point) AS point_gap
  FROM search v
  JOIN gp_origin_edges e ON e.floor_a=v.floor AND e.floor_b=v.floor AND e.ref_edge IS NOT NULL
    AND ST_GeometryType(e.geom)='ST_LineString' AND ST_DWithin(e.geom,v.search_geom,radius_m)
  CROSS JOIN LATERAL (
    SELECT ST_LineLocatePoint(e.geom,ST_ClosestPoint(e.geom,v.search_geom)),false
    UNION
    SELECT ST_LineLocatePoint(e.geom,v.point),false
    UNION
    SELECT n.fraction, (v.area_id IN (dap.from_area,dap.to_area) AND rn.area_id<>v.area_id)
    FROM (VALUES(e.source,0.0::float8),(e.target,1.0::float8)) n(node,fraction)
    LEFT JOIN routing_nodes rn ON rn.id=n.node AND rn.ref_table='door_access_points'
    LEFT JOIN door_access_points dap ON dap.id=rn.ref_id AND dap.floor=v.floor AND NOT COALESCE(dap.needs_review,false)
  ) c(fraction,preferred)
  CROSS JOIN LATERAL (SELECT ST_LineInterpolatePoint(e.geom,c.fraction) AS point) p
  WHERE ST_DWithin(p.point,v.search_geom,radius_m) AND NOT EXISTS (
    SELECT 1 FROM areas a WHERE a.floor=v.floor
      AND a.attrs #>> '{access_policy,public_access}'='false' AND ST_Contains(a.geom,p.point)
  );

  SELECT array_agg(c.node) FILTER(WHERE c.is_origin),array_agg(c.node) FILTER(WHERE NOT c.is_origin)
  INTO origin_candidates,destination_candidates FROM gp_origin_candidates c;
  IF origin_candidates IS NULL THEN RETURN; END IF;

  -- Clip only existing live edges. Origin legs point away from the snapped start;
  -- destination legs point towards the snapped finish. Direct legs on a shared
  -- edge preserve reachability between two interior points of a one-way edge.
  WITH leg AS (
    SELECT e.*, l.src,l.dst,l.from_fraction,l.to_fraction
    FROM gp_origin_edges e JOIN gp_origin_candidates c ON c.graph_edge=e.id
    CROSS JOIN LATERAL (
      SELECT c.node,e.target,c.fraction,1.0 WHERE c.is_origin AND (e.cost>0 OR c.fraction=1)
      UNION ALL SELECT c.node,e.source,c.fraction,0.0 WHERE c.is_origin AND (e.reverse_cost>0 OR c.fraction=0)
      UNION ALL SELECT e.source,c.node,0.0,c.fraction WHERE NOT c.is_origin AND (e.cost>0 OR c.fraction=0)
      UNION ALL SELECT e.target,c.node,1.0,c.fraction WHERE NOT c.is_origin AND (e.reverse_cost>0 OR c.fraction=1)
      UNION ALL
      SELECT c.node,d.node,c.fraction,d.fraction
      FROM gp_origin_candidates d WHERE c.is_origin AND NOT d.is_origin AND d.graph_edge=e.id
        AND (c.fraction=d.fraction OR (c.fraction<d.fraction AND e.cost>0)
             OR (c.fraction>d.fraction AND e.reverse_cost>0))
      UNION ALL
      SELECT c.node,-2,c.fraction,d.fraction
      FROM (SELECT ST_LineLocatePoint(e.geom,p_dest) AS fraction) d
      WHERE c.is_origin AND e.floor_a=p_dest_floor AND dest_area IS NOT NULL
        AND ST_DWithin(e.geom,p_dest,0.001)
        AND NOT EXISTS (SELECT 1 FROM areas a WHERE a.floor=p_dest_floor
          AND a.attrs #>> '{access_policy,public_access}'='false' AND ST_Contains(a.geom,p_dest))
        AND (c.fraction=d.fraction OR (c.fraction<d.fraction AND e.cost>0)
             OR (c.fraction>d.fraction AND e.reverse_cost>0))
    ) l(src,dst,from_fraction,to_fraction)
  ), clipped AS (
    SELECT l.*, CASE
      WHEN l.from_fraction=l.to_fraction THEN ST_MakeLine(p.point,p.point)
      WHEN l.from_fraction<l.to_fraction THEN ST_LineSubstring(l.geom,l.from_fraction,l.to_fraction)
      ELSE ST_Reverse(ST_LineSubstring(l.geom,l.to_fraction,l.from_fraction))
    END AS line
    FROM leg l CROSS JOIN LATERAL (SELECT ST_LineInterpolatePoint(l.geom,l.from_fraction) AS point) p
  )
  INSERT INTO gp_origin_edges(source,target,cost,reverse_cost,ref_edge,door_ref,floor_a,floor_b,geom,a,b,duration,distance)
  SELECT c.src,c.dst,GREATEST(ABS(c.from_fraction-c.to_fraction) *
      CASE WHEN c.from_fraction<=c.to_fraction THEN c.cost ELSE c.reverse_cost END,0.01),
    -1,c.ref_edge,CASE WHEN ST_Length(c.line)>0 THEN c.door_ref END,c.floor_a,c.floor_b,c.line,
    ST_StartPoint(c.line),ST_EndPoint(c.line),ST_Length(c.line)/speed,ST_Length(c.line)
  FROM clipped c;

  SELECT c.node INTO selected_origin
  FROM pgr_dijkstraCost(reverse_sql,-2::bigint,origin_candidates,directed := true) r
  JOIN gp_origin_candidates c ON c.node=r.end_vid
  ORDER BY c.preferred DESC,c.gap,c.point_gap,r.agg_cost,c.ref_edge,c.node LIMIT 1;

  IF FOUND THEN
    selected_destination := -2;
  ELSE
    IF destination_candidates IS NULL THEN RETURN; END IF;
    -- Temporary sink is only used to discover starts that can reach any finish.
    INSERT INTO gp_origin_edges(source,target,cost,reverse_cost)
    SELECT c.node,-3,1,-1 FROM gp_origin_candidates c WHERE NOT c.is_origin;
    SELECT c.node INTO selected_origin
    FROM pgr_dijkstraCost(reverse_sql,-3::bigint,origin_candidates,directed := true) r
    JOIN gp_origin_candidates c ON c.node=r.end_vid
    ORDER BY c.preferred DESC,c.gap,c.point_gap,r.agg_cost,c.ref_edge,c.node LIMIT 1;
    IF NOT FOUND THEN RETURN; END IF;

    SELECT c.node INTO selected_destination
    FROM pgr_dijkstraCost(graph_sql,selected_origin,destination_candidates,directed := true) r
    JOIN gp_origin_candidates c ON c.node=r.end_vid
    ORDER BY c.preferred DESC,c.gap,c.point_gap,r.agg_cost,c.ref_edge,c.node LIMIT 1;
    IF NOT FOUND THEN RETURN; END IF;
  END IF;

  RETURN QUERY
  SELECT 1,r.path_seq::integer,r.node,
    CASE WHEN r.node=e.source THEN e.target ELSE e.source END,e.ref_edge,e.door_ref,
    CASE WHEN r.node=e.source THEN e.floor_a ELSE e.floor_b END,
    CASE WHEN r.node=e.source THEN e.floor_b ELSE e.floor_a END,e.connector_ref,e.kind,
    CASE WHEN e.geom IS NULL THEN NULL ELSE ST_AsGeoJSON(ST_Transform(
      CASE WHEN r.node=e.source THEN e.geom ELSE ST_Reverse(e.geom) END,4326)) END,
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.a ELSE e.b END,4326)),
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.b ELSE e.a END,4326)),
    e.distance,CASE WHEN r.node=e.source THEN e.duration ELSE e.reverse_duration END,
    CASE WHEN r.node=e.source THEN e.cost ELSE e.reverse_cost END, selected_destination<>-2
  FROM pgr_dijkstra(graph_sql,selected_origin,selected_destination,directed := true) r
  JOIN gp_origin_edges e ON e.id=r.edge ORDER BY r.path_seq;
END $$;
COMMIT;
