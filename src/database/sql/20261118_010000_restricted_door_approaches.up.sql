BEGIN;

-- A terminal is a sink. Reaching it never grants passage through a restricted door.
CREATE OR REPLACE FUNCTION public.fn_restricted_door_edges(p_now timestamptz, p_gender gender_enum, p_mode text,
    p_origin_floor smallint, p_dest_floor smallint, p_origin geometry, p_origin_node bigint,
    p_area_ids bigint[], p_access_id bigint)
RETURNS TABLE(access_id bigint, path_id integer, path_seq integer, from_node bigint, to_node bigint,
    edge_id bigint, door_id bigint, from_floor smallint, to_floor smallint, connector_id bigint,
    connector_kind text, geometry_json text, from_point text, to_point text,
    distance_m float8, duration_s float8, route_cost float8)
LANGUAGE plpgsql SET jit TO 'off' AS $function$
DECLARE
  origin_area bigint;
  denied bigint[];
  destinations bigint[];
  speed float8 := CASE p_mode WHEN 'wheelchair' THEN 1.0 WHEN 'van' THEN 5.0 ELSE fn_routing_walk_speed() END;
BEGIN
  SELECT array_agg(a.id) INTO denied FROM areas a
  LEFT JOIN fn_allowed_areas(p_now,p_gender,p_mode,p_dest_floor) ok ON ok.id=a.id AND ok.is_allowed
  WHERE a.floor=p_dest_floor AND a.id=ANY(p_area_ids)
    AND (ok.id IS NULL OR a.attrs #>> '{access_policy,public_access}'='false');
  IF denied IS NULL OR p_origin IS NULL THEN RETURN; END IF;
  IF p_origin_node IS NULL THEN
    origin_area := fn_route_point_area_id(p_now,p_gender,p_mode,p_origin_floor,p_origin);
    IF origin_area IS NULL OR EXISTS (SELECT 1 FROM areas a WHERE a.floor=p_origin_floor
      AND (a.id=ANY(denied) OR a.attrs #>> '{access_policy,public_access}'='false')
      AND ST_Contains(a.geom,p_origin)) THEN RETURN; END IF;
  END IF;
  DROP TABLE IF EXISTS pg_temp.gp_approach_edges;
  CREATE TEMP TABLE gp_approach_edges (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source bigint, target bigint, cost float8, reverse_cost float8,
    ref_edge bigint, door_ref bigint, floor_a smallint, floor_b smallint,
    connector_ref bigint, kind text, geom geometry, a geometry, b geometry,
    duration float8, reverse_duration float8, distance float8
  ) ON COMMIT DROP;

  WITH excluded AS MATERIALIZED (
    SELECT a.id,a.floor,a.geom FROM areas a
    WHERE a.attrs #>> '{access_policy,public_access}'='false' OR a.id=ANY(denied)
  ), excluded_nodes AS MATERIALIZED (
    SELECT n.id FROM routing_nodes n JOIN excluded a ON a.id=n.area_id
  ), excluded_edges AS MATERIALIZED (
    SELECT DISTINCT hit.id FROM excluded z CROSS JOIN LATERAL (
      SELECT se.id FROM routing_edges_static se WHERE se.floor=z.floor AND se.geom && z.geom
        AND ST_Relate(se.geom,z.geom,'T********') OFFSET 0
    ) hit
  )
  INSERT INTO gp_approach_edges(source,target,cost,reverse_cost,ref_edge,door_ref,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT e.src,e.dst,e.cost/speed,CASE WHEN e.reverse_cost<0 THEN -1 ELSE e.reverse_cost/speed END,
    e.id,e.door_id,f.floor,f.floor,e.geom,ST_StartPoint(e.geom),ST_EndPoint(e.geom),
    COALESCE(sfs.travel_seconds,ST_Length(e.geom)/speed),COALESCE(sfs.reverse_seconds,sfs.travel_seconds,ST_Length(e.geom)/speed),ST_Length(e.geom)
  FROM routing_floors f CROSS JOIN LATERAL fn_routing_edges_live_param(p_now,p_gender,p_mode,f.floor) e
  LEFT JOIN public.routing_edges_static sfs_edge ON sfs_edge.id=e.id AND sfs_edge.attrs->>'edge_type'='same_floor_stair'
  LEFT JOIN public.same_floor_stairs sfs ON sfs.id=(sfs_edge.attrs->>'stair_id')::bigint
  WHERE (e.cost>0 OR e.reverse_cost>0) AND e.geom IS NOT NULL AND NOT ST_IsEmpty(e.geom)
    AND e.src NOT IN (SELECT id FROM excluded_nodes)
    AND e.dst NOT IN (SELECT id FROM excluded_nodes)
    AND e.id NOT IN (SELECT id FROM excluded_edges);

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
    WHERE NOT EXISTS (SELECT 1 FROM areas z WHERE z.floor=n.floor AND z.id=ANY(denied) AND ST_Contains(z.geom,n.geom))
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
  INSERT INTO gp_approach_edges(source,target,cost,reverse_cost,floor_a,floor_b,connector_ref,kind,a,b,duration,reverse_duration,distance)
  SELECT t.source,t.target,CASE WHEN t.direction='reverse' THEN -1 ELSE t.forward_s END,
    CASE WHEN t.direction='forward' THEN -1 ELSE t.reverse_s END,t.floor_a,t.floor_b,t.connector_id,t.kind,t.a,t.b,
    t.forward_s,t.reverse_s,0 FROM timed t;

  -- An explicit origin door already has a validated public graph node.
  INSERT INTO gp_approach_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,distance)
  SELECT -1,n.id,0.01,-1,p_origin_floor,p_origin_floor,ST_MakeLine(p_origin,n.geom),p_origin,n.geom,0,0
  FROM routing_nodes n
  WHERE n.id=p_origin_node AND n.floor=p_origin_floor AND (ST_Equals(n.geom,p_origin) OR fn_route_line_valid_inside_area(n.area_id,ST_MakeLine(p_origin,n.geom),0.20))
    AND NOT EXISTS (SELECT 1 FROM areas z WHERE z.floor=n.floor AND (z.id=ANY(denied) OR z.attrs #>> '{access_policy,public_access}'='false') AND ST_Relate(ST_MakeLine(p_origin,n.geom),z.geom,'T********'))
    AND EXISTS (SELECT 1 FROM gp_approach_edges e WHERE e.source=n.id OR e.target=n.id);


  -- Each exterior DAP is reached from the allowed side. An ordinary final approach
  -- may use the last intra-area edge even when crossing the door itself is forbidden.
  WITH boundary AS MATERIALIZED (
    SELECT p.id,p.geom,p.door_id,
      CASE WHEN p.from_area=ANY(denied) THEN p.to_area ELSE p.from_area END AS exterior
    FROM door_access_points p
    WHERE p.floor=p_dest_floor AND NOT COALESCE(p.needs_review,false)
      AND (p.from_area=ANY(denied) OR p.to_area=ANY(denied))
  ), outside_nodes AS MATERIALIZED (
    SELECT b.*,n.id AS node,n.geom AS node_geom
    FROM boundary b JOIN routing_nodes n ON n.area_id=b.exterior AND n.ref_id=b.id
      AND n.floor=p_dest_floor AND n.ref_table='door_access_points'
    JOIN fn_allowed_areas(p_now,p_gender,p_mode,p_dest_floor) a ON a.id=n.area_id AND a.is_allowed
    WHERE NOT n.area_id=ANY(p_area_ids) AND (a.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
      AND (ST_Equals(b.geom,n.geom) OR fn_route_line_valid_inside_area(b.exterior,ST_MakeLine(n.geom,b.geom),0.20))
  ), legs AS (
    SELECT b.id,b.node AS source,ST_MakeLine(b.node_geom,b.geom) AS geom,NULL::bigint AS ref_edge,
      NULL::float8 AS seconds,NULL::float8 AS weight FROM outside_nodes b
    UNION ALL
    SELECT b.id,peer.id,
      CASE WHEN ST_Equals(b.node_geom,b.geom) THEN g.line ELSE ST_AddPoint(g.line,b.geom) END,
      e.id,NULL,NULL
    FROM outside_nodes b JOIN routing_edges_static e ON e.floor=p_dest_floor AND b.node IN(e.src,e.dst)
    JOIN routing_nodes peer ON peer.id=CASE WHEN e.src=b.node THEN e.dst ELSE e.src END
      AND peer.area_id=b.exterior AND peer.ref_table='door_access_points'
    CROSS JOIN LATERAL (SELECT CASE WHEN e.dst=b.node THEN e.geom ELSE ST_Reverse(e.geom) END) g(line)
    WHERE e.door_id IS NULL AND e.attrs->>'edge_type'='intra_area'
      AND e.attrs->>'safety' IS DISTINCT FROM 'unsafe_direct_fallback'
      AND (e.attrs->>'valid_inside_area') IS DISTINCT FROM 'false'
      AND fn_route_line_valid_inside_area(b.exterior,g.line,0.20)
    UNION ALL
    -- A registered stair's landing node may belong to the denied hall. Use the
    -- authorized stair up to its DAP as the last edge, without entering that node.
    SELECT b.id, CASE WHEN s.end_access_id=b.id THEN e.src ELSE e.dst END,
      CASE WHEN s.end_access_id=b.id THEN e.geom ELSE ST_Reverse(e.geom) END,e.id,
      CASE WHEN s.end_access_id=b.id THEN s.travel_seconds ELSE COALESCE(s.reverse_seconds,s.travel_seconds) END,
      CASE WHEN s.end_access_id=b.id THEN perm.forward_cost ELSE perm.reverse_cost END * perm.penalty / speed
    FROM boundary b JOIN same_floor_stairs s ON s.stair_area_id=b.exterior AND s.floor=p_dest_floor
      AND b.id IN(s.start_access_id,s.end_access_id)
    JOIN routing_edges_static e ON e.floor=s.floor AND e.attrs->>'edge_type'='same_floor_stair'
      AND e.attrs->>'stair_id'=s.id::text
    JOIN routing_nodes landing ON landing.id=CASE WHEN s.end_access_id=b.id THEN e.dst ELSE e.src END
      AND landing.ref_id=b.id AND landing.area_id=ANY(denied) AND ST_Equals(landing.geom,b.geom)
    CROSS JOIN LATERAL fn_same_floor_stair_access(s.id,p_now,p_gender,p_mode) perm
    WHERE (s.end_access_id=b.id AND perm.direction<>'reverse') OR (s.start_access_id=b.id AND perm.direction<>'forward')
  )
  INSERT INTO gp_approach_edges(source,target,cost,reverse_cost,ref_edge,floor_a,floor_b,geom,a,b,duration,distance)
  SELECT l.source,-2-l.id,GREATEST(COALESCE(l.weight,ST_Length(l.geom)/speed),0.01),-1,
    l.ref_edge,p_dest_floor,p_dest_floor,l.geom,ST_StartPoint(l.geom),ST_EndPoint(l.geom),
    COALESCE(l.seconds,ST_Length(l.geom)/speed),ST_Length(l.geom)
  FROM legs l WHERE NOT EXISTS (SELECT 1 FROM areas z WHERE z.floor=p_dest_floor
      AND (z.id=ANY(denied) OR z.attrs #>> '{access_policy,public_access}'='false')
      AND z.geom && l.geom AND (ST_Relate(l.geom,z.geom,'T********') OR ST_Contains(z.geom,ST_EndPoint(l.geom))));

  -- Snap a coordinate within its own permitted area. Only the directed portion
  -- of the chosen static edge appears in the route. Never draw the off-network gap.
  IF p_origin_node IS NULL THEN
    WITH candidates AS MATERIALIZED (
      SELECT e.id,e.geom,ST_ClosestPoint(e.geom,p_origin) AS point
      FROM gp_approach_edges e
      JOIN routing_edges_static se ON se.id=e.ref_edge AND se.floor=p_origin_floor
      JOIN routing_nodes ns ON ns.id=se.src AND ns.area_id=origin_area
      JOIN routing_nodes nd ON nd.id=se.dst AND nd.area_id=origin_area
      WHERE e.floor_a=p_origin_floor AND e.floor_b=p_origin_floor
        AND se.door_id IS NULL AND se.attrs->>'edge_type' IS DISTINCT FROM 'same_floor_stair'
        AND ST_Length(e.geom)>0
    ), visible AS (
      SELECT c.*,ST_Distance(p_origin,c.point) AS gap FROM candidates c
      WHERE (ST_DWithin(p_origin,c.point,0.001)
        OR fn_route_line_valid_inside_area(origin_area,ST_MakeLine(p_origin,c.point),0.10))
        AND NOT EXISTS (SELECT 1 FROM areas a WHERE a.floor=p_origin_floor
          AND (a.id=ANY(denied) OR a.attrs #>> '{access_policy,public_access}'='false')
          AND a.geom && ST_MakeLine(p_origin,c.point)
          AND ST_Relate(ST_MakeLine(p_origin,c.point),a.geom,'T********'))
    ), snapped AS MATERIALIZED (
      SELECT v.*,ST_LineLocatePoint(v.geom,v.point) AS fraction FROM visible v
      ORDER BY gap FETCH FIRST 1 ROW WITH TIES
    )
    INSERT INTO gp_approach_edges(source,target,cost,reverse_cost,ref_edge,floor_a,floor_b,geom,a,b,duration,distance)
    SELECT -1,l.node,GREATEST(l.weight,0.01),-1,e.ref_edge,p_origin_floor,p_origin_floor,
      l.line,s.point,ST_EndPoint(l.line),ST_Length(l.line)/speed,ST_Length(l.line)
    FROM snapped s JOIN gp_approach_edges e ON e.id=s.id
    CROSS JOIN LATERAL (
      SELECT e.target,e.cost*(1-s.fraction),
        CASE WHEN s.fraction=1 THEN ST_MakeLine(e.b,e.b) ELSE ST_LineSubstring(e.geom,s.fraction,1) END
      WHERE e.cost>0 OR s.fraction=1
      UNION ALL SELECT e.source,e.reverse_cost*s.fraction,
        CASE WHEN s.fraction=0 THEN ST_MakeLine(e.a,e.a) ELSE ST_Reverse(ST_LineSubstring(e.geom,0,s.fraction)) END
      WHERE e.reverse_cost>0 OR s.fraction=0
    ) l(node,weight,line);
    IF NOT FOUND THEN
      -- Some legal landings have only a vertical connector and no walking edge.
      INSERT INTO gp_approach_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,distance)
      SELECT -1,n.id,0.01,-1,p_origin_floor,p_origin_floor,ST_MakeLine(p.geom,p.geom),p.geom,p.geom,0,0
      FROM routing_nodes n JOIN door_access_points p ON p.id=n.ref_id AND p.floor=n.floor AND ST_Equals(n.geom,p.geom)
      WHERE n.area_id=origin_area AND n.floor=p_origin_floor AND n.ref_table='door_access_points'
        AND EXISTS (SELECT 1 FROM gp_approach_edges e WHERE (e.source=n.id AND e.cost>0) OR (e.target=n.id AND e.reverse_cost>0))
        AND (ST_DWithin(p.geom,p_origin,0.001) OR fn_route_line_valid_inside_area(origin_area,ST_MakeLine(p_origin,p.geom),0.10))
        AND NOT EXISTS (SELECT 1 FROM areas z WHERE z.floor=n.floor AND (z.id=ANY(denied) OR z.attrs #>> '{access_policy,public_access}'='false')
          AND ST_Relate(ST_MakeLine(p_origin,p.geom),z.geom,'T********'))
      ORDER BY ST_Distance(p.geom,p_origin) FETCH FIRST 1 ROW WITH TIES;
    END IF;
  END IF;
  SELECT array_agg(DISTINCT target) INTO destinations FROM gp_approach_edges WHERE target < -2;
  IF destinations IS NULL THEN RETURN; END IF;
  IF p_access_id IS NULL THEN
    RETURN QUERY SELECT -2-r.end_vid,1,0,NULL::bigint,NULL::bigint,NULL::bigint,NULL::bigint,
      NULL::smallint,NULL::smallint,NULL::bigint,NULL::text,NULL::text,NULL::text,NULL::text,
      NULL::float8,NULL::float8,r.agg_cost FROM pgr_dijkstraCost(
        'SELECT id,source,target,cost,reverse_cost FROM pg_temp.gp_approach_edges',-1::bigint,destinations,true) r;
    RETURN;
  END IF;
  RETURN QUERY
  SELECT p_access_id,1,r.path_seq::integer,r.node,CASE WHEN r.node=e.source THEN e.target ELSE e.source END,
    e.ref_edge,e.door_ref,CASE WHEN r.node=e.source THEN e.floor_a ELSE e.floor_b END,
    CASE WHEN r.node=e.source THEN e.floor_b ELSE e.floor_a END,e.connector_ref,e.kind,
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.geom ELSE ST_Reverse(e.geom) END,4326)),
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.a ELSE e.b END,4326)),
    ST_AsGeoJSON(ST_Transform(CASE WHEN r.node=e.source THEN e.b ELSE e.a END,4326)),
    e.distance,CASE WHEN r.node=e.source THEN e.duration ELSE e.reverse_duration END,
    CASE WHEN r.node=e.source THEN e.cost ELSE e.reverse_cost END
  FROM pgr_dijkstra('SELECT id,source,target,cost,reverse_cost FROM pg_temp.gp_approach_edges',
    -1::bigint,-2-p_access_id,true) r JOIN gp_approach_edges e ON e.id=r.edge ORDER BY r.path_seq;
END $function$;

DO $migration$
DECLARE
    original regprocedure := 'public.fn_route_private_area_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,bigint,bigint)'::regprocedure;
    destination regprocedure;
    permission record;
BEGIN
    -- Preserve the existing execute policy for the same routing callers.
    destination := 'public.fn_restricted_door_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,bigint,bigint[],bigint)'::regprocedure;
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
