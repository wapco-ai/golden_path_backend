
-- Install the private destination router without modifying the existing nearby router.
BEGIN;

CREATE OR REPLACE FUNCTION public.fn_route_private_area_edges(p_now timestamp with time zone, p_gender gender_enum, p_mode text, p_origin_floor smallint, p_dest_floor smallint, p_origin geometry, p_area_id bigint, p_origin_node bigint)
 RETURNS TABLE(path_id integer, path_seq integer, from_node bigint, to_node bigint, edge_id bigint, door_id bigint, from_floor smallint, to_floor smallint, connector_id bigint, connector_kind text, geometry_json text, from_point text, to_point text, distance_m double precision, duration_s double precision, route_cost double precision, terminal_kind text)
 LANGUAGE plpgsql
 SET jit TO 'off'
AS $function$
DECLARE
  origin_area bigint;
  target_area bigint;
  search_geom geometry;
  terminal record;
  terminal_geom geometry;
  radius_m CONSTANT float8 := 60.0;
  speed float8 := CASE p_mode WHEN 'wheelchair' THEN 1.0 WHEN 'van' THEN 5.0 ELSE public.fn_routing_walk_speed() END;
BEGIN
  origin_area := fn_route_point_area_id(p_now,p_gender,p_mode,p_origin_floor,p_origin);
  IF (p_origin_node IS NULL AND origin_area IS NULL) OR EXISTS (
    SELECT 1 FROM areas a WHERE a.id=origin_area AND a.attrs #>> '{access_policy,public_access}'='false'
  ) THEN RETURN; END IF;

  SELECT a.id, walls.geom INTO target_area,search_geom
  FROM areas a
  CROSS JOIN LATERAL (
    SELECT ST_Collect(ST_ExteriorRing(parts.geom)) AS geom
    FROM ST_Dump(ST_CollectionExtract(ST_MakeValid(a.geom),3)) parts
  ) walls
  WHERE a.id=p_area_id AND a.floor=p_dest_floor
    AND a.attrs #>> '{access_policy,public_access}'='false';
  IF search_geom IS NULL THEN RETURN; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM routing_edges_static e
    WHERE e.floor=p_dest_floor AND ST_DWithin(e.geom,search_geom,radius_m)
  ) THEN RETURN; END IF;

  DROP TABLE IF EXISTS pg_temp.gp_nearby_edges;
  CREATE TEMP TABLE gp_nearby_edges (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source bigint, target bigint, cost float8, reverse_cost float8,
    ref_edge bigint, door_ref bigint, floor_a smallint, floor_b smallint,
    connector_ref bigint, kind text, geom geometry, a geometry, b geometry,
    duration float8, reverse_duration float8, distance float8
  ) ON COMMIT DROP;

  WITH private_nodes AS MATERIALIZED (
    SELECT array_agg(n.id) AS node_ids FROM routing_nodes n JOIN areas a ON a.id=n.area_id
    WHERE a.attrs #>> '{access_policy,public_access}'='false'
  )
  INSERT INTO gp_nearby_edges(source,target,cost,reverse_cost,ref_edge,door_ref,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT e.src,e.dst,e.cost/speed,CASE WHEN e.reverse_cost<0 THEN -1 ELSE e.reverse_cost/speed END,
    e.id,e.door_id,f.floor,f.floor,e.geom,ST_StartPoint(e.geom),ST_EndPoint(e.geom),
    COALESCE(sfs.travel_seconds,ST_Length(e.geom)/speed),COALESCE(sfs.reverse_seconds,sfs.travel_seconds,ST_Length(e.geom)/speed),ST_Length(e.geom)
  FROM routing_floors f CROSS JOIN LATERAL fn_routing_edges_live_param(p_now,p_gender,p_mode,f.floor) e
  LEFT JOIN public.routing_edges_static sfs_edge ON sfs_edge.id=e.id AND sfs_edge.attrs->>'edge_type'='same_floor_stair'
  LEFT JOIN public.same_floor_stairs sfs ON sfs.id=(sfs_edge.attrs->>'stair_id')::bigint
  WHERE (e.cost>0 OR e.reverse_cost>0) AND e.geom IS NOT NULL AND NOT ST_IsEmpty(e.geom)
    AND array_position((SELECT node_ids FROM private_nodes),e.src) IS NULL
    AND array_position((SELECT node_ids FROM private_nodes),e.dst) IS NULL
    AND NOT EXISTS (
      SELECT 1 FROM areas a
      WHERE a.floor=f.floor AND a.attrs #>> '{access_policy,public_access}'='false'
        AND a.geom && e.geom AND ST_Relate(e.geom,a.geom,'T********')
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
  ), stop_candidates AS MATERIALIZED (
    SELECT n.* FROM routing_connector_nodes n JOIN ready_connectors rc ON rc.id=n.connector_id
    WHERE p_mode <> 'van' AND (p_mode <> 'wheelchair' OR n.kind NOT IN ('stair','escalator'))
  ), stop_floors AS MATERIALIZED (
    SELECT DISTINCT floor FROM stop_candidates
  ), stop_allowed_doors AS MATERIALIZED (
    SELECT f.floor, d.id
    FROM stop_floors f
    CROSS JOIN LATERAL fn_allowed_doors(p_now,p_gender,p_mode,f.floor) d
    WHERE d.is_allowed
  ), stop_allowed_areas AS MATERIALIZED (
    SELECT f.floor, a.id
    FROM stop_floors f
    CROSS JOIN LATERAL fn_allowed_areas(p_now,p_gender,p_mode,f.floor) a
    WHERE a.is_allowed
      AND (a.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
  ), valid_stops AS (
    SELECT n.*
    FROM stop_candidates n
    JOIN stop_allowed_doors d ON d.floor=n.floor AND d.id=n.door_id
    JOIN stop_allowed_areas a ON a.floor=n.floor AND a.id=n.area_id
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
  INSERT INTO gp_nearby_edges(source,target,cost,reverse_cost,floor_a,floor_b,connector_ref,kind,a,b,duration,reverse_duration,distance)
  SELECT t.source,t.target,CASE WHEN t.direction='reverse' THEN -1 ELSE t.forward_s END,
    CASE WHEN t.direction='forward' THEN -1 ELSE t.reverse_s END,t.floor_a,t.floor_b,t.connector_id,t.kind,t.a,t.b,
    t.forward_s,t.reverse_s,0 FROM timed t;

  -- Every anchor remains confined to its own floor and validated walkable area.
  INSERT INTO gp_nearby_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT -1,n.id,GREATEST(ST_Length(g.geom)/speed,0.01),-1,p_origin_floor,p_origin_floor,
    g.geom,p_origin,n.geom,ST_Length(g.geom)/speed,ST_Length(g.geom)/speed,ST_Length(g.geom)
  FROM routing_nodes n CROSS JOIN LATERAL (
    SELECT public.fn_build_intra_area_edge_geom(origin_area,p_origin_floor,p_origin,n.geom,0.10) AS geom
  ) g WHERE n.floor=p_origin_floor AND n.area_id=origin_area AND n.ref_table='door_access_points'
    AND EXISTS (SELECT 1 FROM gp_nearby_edges e WHERE e.source=n.id OR e.target=n.id)
    AND p_origin_node IS NULL
    AND g.geom IS NOT NULL AND public.fn_route_line_valid_inside_area(origin_area,g.geom,0.10)
    AND NOT EXISTS (
      SELECT 1 FROM areas private_area
      WHERE private_area.floor=p_origin_floor
        AND private_area.attrs #>> '{access_policy,public_access}'='false'
        AND private_area.geom && g.geom AND ST_Relate(g.geom,private_area.geom,'T********')
    );

  -- An explicit origin door already has a validated public graph node.
  INSERT INTO gp_nearby_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,distance)
  SELECT -1,n.id,0.01,-1,p_origin_floor,p_origin_floor,ST_MakeLine(n.geom,n.geom),n.geom,n.geom,0,0
  FROM routing_nodes n
  WHERE n.id=p_origin_node AND n.floor=p_origin_floor AND ST_DWithin(n.geom,p_origin,0.001)
    AND EXISTS (SELECT 1 FROM gp_nearby_edges e WHERE e.source=n.id OR e.target=n.id);

  -- Consider only the requested floor. Each candidate carries the original
  -- edge and its direction, so a reachable node on the far side of a one-way
  -- edge cannot make an interior point reachable in reverse.
  WITH nearby_edges AS MATERIALIZED (
    SELECT e.*
    FROM gp_nearby_edges e
    WHERE e.floor_a=p_dest_floor AND e.floor_b=p_dest_floor
      AND e.ref_edge IS NOT NULL AND ST_GeometryType(e.geom)='ST_LineString'
      AND NOT EXISTS (SELECT 1 FROM public.routing_edges_static se WHERE se.id=e.ref_edge AND se.attrs->>'edge_type'='same_floor_stair')
      AND ST_DWithin(e.geom,search_geom,radius_m)
  ), allowed_destination_doors AS MATERIALIZED (
    SELECT id FROM fn_allowed_doors(p_now,p_gender,p_mode,p_dest_floor) WHERE is_allowed
  ), preferred_nodes AS MATERIALIZED (
    SELECT n.id
    FROM routing_nodes n
    JOIN door_access_points dap ON dap.id=n.ref_id AND dap.floor=n.floor AND ST_Equals(n.geom,dap.geom)
    JOIN allowed_destination_doors d ON d.id=dap.door_id
    JOIN areas adjacent ON adjacent.id=n.area_id AND adjacent.floor=n.floor
    WHERE n.floor=p_dest_floor AND n.ref_table='door_access_points'
      AND n.area_id<>target_area AND target_area IN (dap.from_area,dap.to_area)
      AND n.area_id IN (dap.from_area,dap.to_area)
      AND (adjacent.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
      AND NOT COALESCE(dap.needs_review,false)
  ), candidates AS MATERIALIZED (
    SELECT e.*, c.fraction, c.preferred, ST_LineInterpolatePoint(e.geom,c.fraction) AS endpoint
    FROM nearby_edges e
    CROSS JOIN LATERAL (
      SELECT ST_LineLocatePoint(e.geom,ST_ClosestPoint(e.geom,search_geom)),false
      UNION ALL SELECT 0.0,EXISTS (SELECT 1 FROM preferred_nodes n WHERE n.id=e.source)
      UNION ALL SELECT 1.0,EXISTS (SELECT 1 FROM preferred_nodes n WHERE n.id=e.target)
    ) c(fraction,preferred)
  ), legal AS MATERIALIZED (
    SELECT c.*
    FROM candidates c
    WHERE ST_DWithin(c.endpoint,search_geom,radius_m)
      AND NOT EXISTS (
        SELECT 1 FROM areas a WHERE a.floor=p_dest_floor
          AND a.attrs #>> '{access_policy,public_access}'='false'
          AND ST_Contains(a.geom,c.endpoint)
      )
  ), legs AS MATERIALIZED (
    SELECT c.*, l.node, l.start_fraction, l.leg_cost
    FROM legal c
    CROSS JOIN LATERAL (
      SELECT c.source,0.0,c.cost*c.fraction WHERE c.fraction=0 OR c.cost>0
      UNION ALL
      SELECT c.target,1.0,c.reverse_cost*(1-c.fraction) WHERE c.fraction=1 OR c.reverse_cost>0
      UNION ALL
      -- The origin may already be on this edge between its two nodes.
      SELECT -1::bigint, o.fraction,
        CASE WHEN c.fraction>=o.fraction THEN c.cost*(c.fraction-o.fraction)
             ELSE c.reverse_cost*(o.fraction-c.fraction) END
      FROM (SELECT ST_LineLocatePoint(c.geom,p_origin) AS fraction) o
      WHERE p_origin_floor=p_dest_floor AND ST_DWithin(c.geom,p_origin,0.001)
        AND (c.fraction=o.fraction OR (c.fraction>o.fraction AND c.cost>0)
             OR (c.fraction<o.fraction AND c.reverse_cost>0))
    ) l(node,start_fraction,leg_cost)
  ), reachable AS (
    SELECT -1::bigint AS node, 0.0::float8 AS agg_cost
    UNION ALL
    SELECT r.end_vid,r.agg_cost
    FROM pgr_dijkstraCost(
      'SELECT id,source,target,cost,reverse_cost FROM pg_temp.gp_nearby_edges',
      -1::bigint, ARRAY(SELECT DISTINCT l.node FROM legs l WHERE l.node<>-1), directed := true
    ) r
  )
  SELECT l.* INTO terminal
  FROM legs l JOIN reachable r ON r.node=l.node
  ORDER BY l.preferred DESC,
    CASE WHEN NOT l.preferred THEN ST_Distance(l.endpoint,search_geom) END,
    r.agg_cost+l.leg_cost, l.ref_edge, l.node
  LIMIT 1;
  IF NOT FOUND THEN RETURN; END IF;

  terminal_geom := CASE
    WHEN terminal.start_fraction=terminal.fraction OR ST_Length(terminal.geom)=0 THEN ST_MakeLine(terminal.endpoint,terminal.endpoint)
    WHEN terminal.start_fraction<terminal.fraction THEN ST_LineSubstring(terminal.geom,terminal.start_fraction,terminal.fraction)
    ELSE ST_Reverse(ST_LineSubstring(terminal.geom,terminal.fraction,terminal.start_fraction))
  END;
  INSERT INTO gp_nearby_edges(source,target,cost,reverse_cost,ref_edge,door_ref,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  VALUES (terminal.node,-2,GREATEST(terminal.leg_cost,0.01),-1,terminal.ref_edge,
    CASE WHEN ST_Length(terminal_geom)>0 THEN terminal.door_ref END,
    p_dest_floor,p_dest_floor,terminal_geom,ST_StartPoint(terminal_geom),terminal.endpoint,
    ST_Length(terminal_geom)/speed,NULL,ST_Length(terminal_geom));

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
    CASE WHEN r.node=e.source THEN e.cost ELSE e.reverse_cost END,
    CASE WHEN e.target=-2 THEN CASE WHEN terminal.preferred THEN 'connection' ELSE 'wall' END END
  FROM pgr_dijkstra(
    'SELECT id,source,target,cost,reverse_cost FROM pg_temp.gp_nearby_edges',
    -1::bigint,-2::bigint,directed := true
  ) r JOIN gp_nearby_edges e ON e.id=r.edge ORDER BY r.path_seq;
END $function$;

DO $migration$
DECLARE
    original regprocedure := 'public.fn_route_nearby_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,geometry)'::regprocedure;
    destination regprocedure;
    permission record;
BEGIN
    -- Preserve the existing execute policy for the same routing callers.
    destination := 'public.fn_route_private_area_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,bigint,bigint)'::regprocedure;
    EXECUTE format('REVOKE EXECUTE ON FUNCTION %s FROM PUBLIC', destination);
    FOR permission IN
        SELECT a.grantee, a.is_grantable, r.rolname
        FROM pg_proc p
        CROSS JOIN LATERAL aclexplode(COALESCE(p.proacl, acldefault('f', p.proowner))) a
        LEFT JOIN pg_roles r ON r.oid = a.grantee
        WHERE p.oid = original AND a.privilege_type = 'EXECUTE' AND a.grantee <> p.proowner
    LOOP
        EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO %s%s', destination,
            CASE WHEN permission.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(permission.rolname) END,
            CASE WHEN permission.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END);
    END LOOP;
END
$migration$;

COMMIT;
