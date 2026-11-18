-- Reuse ordinary within-area starts after choosing the reachable snap point.
BEGIN;
DO $migration$
DECLARE
    signature regprocedure := 'public.fn_route_nearby_origin_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,geometry)'::regprocedure;
    definition text := pg_get_functiondef(signature);
    candidates text := '  DROP TABLE IF EXISTS pg_temp.gp_origin_candidates;';
    restrictions text := $sql$  -- BEGIN nearby endpoint restrictions
  DROP TABLE IF EXISTS pg_temp.gp_origin_excluded_areas;
  CREATE TEMP TABLE gp_origin_excluded_areas ON COMMIT DROP AS
  WITH allowed AS MATERIALIZED (
    SELECT f.floor,a.id FROM (SELECT p_origin_floor AS floor UNION SELECT p_dest_floor) f
    CROSS JOIN LATERAL fn_allowed_areas(p_now,p_gender,p_mode,f.floor) a
    WHERE a.is_allowed AND a.attrs #>> '{access_policy,public_access}' IS DISTINCT FROM 'false'
  )
  SELECT z.floor,z.geom FROM areas z
  WHERE z.floor IN (p_origin_floor,p_dest_floor)
    AND NOT EXISTS (SELECT 1 FROM allowed a WHERE a.floor=z.floor AND a.id=z.id);
  CREATE INDEX ON gp_origin_excluded_areas USING gist(geom);

  -- The other end of a new route can also lie inside an area with a closed
  -- subarea. Validate the existing destination legs before testing reachability.
  DELETE FROM gp_origin_edges e WHERE e.target=-2 AND EXISTS (
    SELECT 1 FROM gp_origin_excluded_areas z WHERE z.floor=p_dest_floor
      AND z.geom && e.geom AND ST_Relate(e.geom,z.geom,'T********')
  );
  -- END nearby endpoint restrictions
$sql$;
    insertion text := E'  RETURN QUERY\n  SELECT 1,r.path_seq::integer,r.node,';
    anchors text := $sql$  -- BEGIN snapped-origin area anchors
  DECLARE
    snap geometry;
    snap_area bigint;
    ordinary_start CONSTANT bigint := -4;
  BEGIN
    -- Keep the directed start on door transitions: two nodes at identical XY
    -- may represent opposite sides. An intra-area edge has an unambiguous side.
    SELECT c.point, ns.area_id INTO snap, snap_area
    FROM gp_origin_candidates c
    JOIN gp_origin_edges e ON e.id=c.graph_edge
    JOIN routing_edges_static s ON s.id=e.ref_edge
    JOIN routing_nodes ns ON ns.id=s.src
    JOIN routing_nodes nd ON nd.id=s.dst AND nd.area_id=ns.area_id
    WHERE c.node=selected_origin AND s.attrs->>'edge_type'='intra_area'
      AND e.cost>0 AND e.reverse_cost>0
      AND ns.area_id=fn_route_point_area_id(p_now,p_gender,p_mode,p_origin_floor,c.point);

    IF snap_area IS NOT NULL THEN
      WITH targets(node, point) AS (
        SELECT n.id,n.geom FROM routing_nodes n
        WHERE n.floor=p_origin_floor AND n.area_id=snap_area AND n.ref_table='door_access_points'
          AND EXISTS (SELECT 1 FROM gp_origin_edges e WHERE
            (e.source=n.id AND e.target>0 AND e.cost>0) OR
            (e.target=n.id AND e.source>0 AND e.reverse_cost>0))
        UNION ALL
        SELECT selected_destination,p_dest WHERE selected_destination=-2
          AND p_origin_floor=p_dest_floor AND dest_area=snap_area
        UNION ALL
        SELECT c.node,c.point FROM gp_origin_candidates c
        JOIN gp_origin_edges e ON e.id=c.graph_edge
        JOIN routing_edges_static s ON s.id=e.ref_edge AND s.attrs->>'edge_type'='intra_area'
        JOIN routing_nodes ns ON ns.id=s.src AND ns.area_id=snap_area
        JOIN routing_nodes nd ON nd.id=s.dst AND nd.area_id=snap_area
        WHERE c.node=selected_destination AND c.floor=p_origin_floor AND e.cost>0 AND e.reverse_cost>0
      ), legs AS MATERIALIZED (
        SELECT node,point,fn_build_intra_area_edge_geom(snap_area,p_origin_floor,snap,point,0.10) AS geom
        FROM targets
      )
      INSERT INTO gp_origin_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,distance)
      SELECT ordinary_start,l.node,GREATEST(ST_Length(l.geom)/speed,0.01),-1,p_origin_floor,p_origin_floor,
        l.geom,snap,l.point,ST_Length(l.geom)/speed,ST_Length(l.geom)
      FROM legs l
      WHERE l.geom IS NOT NULL AND fn_route_line_valid_inside_area(snap_area,l.geom,0.10)
        AND NOT EXISTS (
          SELECT 1 FROM gp_origin_excluded_areas z WHERE z.floor=p_origin_floor AND z.geom && l.geom
            AND ST_Relate(l.geom,z.geom,'T********')
        );

      -- Use the ordinary start only if it reaches the already selected finish.
      -- Otherwise retain the legal directed path along the original edge.
      IF FOUND AND EXISTS (SELECT 1 FROM pgr_dijkstraCost(graph_sql,ordinary_start,selected_destination,directed := true)) THEN
        selected_origin := ordinary_start;
      END IF;
    END IF;
  END;
  -- END snapped-origin area anchors
$sql$;
BEGIN
    IF strpos(definition,anchors)>0 AND strpos(definition,restrictions)>0 THEN RETURN; END IF;
    IF strpos(definition,'  -- BEGIN snapped-origin area anchors')>0
       OR strpos(definition,'  -- BEGIN nearby endpoint restrictions')>0
       OR length(definition)-length(replace(definition,insertion,'')) <> length(insertion)
       OR length(definition)-length(replace(definition,candidates,'')) <> length(candidates) THEN
        RAISE EXCEPTION 'Unexpected nearby-origin path output';
    END IF;
    EXECUTE replace(replace(definition,candidates,restrictions || candidates),insertion,anchors || insertion);
END $migration$;
COMMIT;
