-- Restore the previous nearby-route queries and remove the prepared helpers.
BEGIN;
CREATE FUNCTION pg_temp.replace_endpoint_sql(body text, original text, replacement text, expected integer DEFAULT 1)
RETURNS text LANGUAGE plpgsql AS $patch$
BEGIN
    IF length(body)-length(replace(body,original,'')) <> expected*length(original) THEN
        RAISE EXCEPTION 'Unexpected endpoint SQL fragment: %', left(original,120);
    END IF;
    RETURN replace(body,original,replacement);
END;
$patch$;

DO $migration$
DECLARE
    destination_signature constant text := 'public.fn_route_nearby_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)';
    origin_signature constant text := 'public.fn_route_nearby_origin_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)';
    signature text;
    definition text;
    graph_insert text;
    private_cte constant text := $sql$  WITH private_nodes AS MATERIALIZED (
    SELECT n.id FROM routing_nodes n JOIN areas a ON a.id=n.area_id
    WHERE a.attrs #>> '{access_policy,public_access}'='false'
  )
$sql$;
    old_private_filter constant text := $sql$    AND NOT EXISTS (
      SELECT 1 FROM routing_nodes n JOIN areas a ON a.id=n.area_id
      WHERE n.id IN (e.src,e.dst) AND a.attrs #>> '{access_policy,public_access}'='false'
    );$sql$;
    new_private_filter constant text := $sql$    AND NOT EXISTS (SELECT 1 FROM private_nodes n WHERE n.id=e.src)
    AND NOT EXISTS (SELECT 1 FROM private_nodes n WHERE n.id=e.dst);$sql$;
    old_anchor constant text := $sql$  INSERT INTO gp_nearby_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT -1,n.id,GREATEST(ST_Length(g.geom)/speed,0.01),-1,p_origin_floor,p_origin_floor,
    g.geom,p_origin,n.geom,ST_Length(g.geom)/speed,ST_Length(g.geom)/speed,ST_Length(g.geom)
  FROM routing_nodes n CROSS JOIN LATERAL (
    SELECT fn_build_intra_area_edge_geom(origin_area,p_origin_floor,p_origin,n.geom,0.10) AS geom
  ) g WHERE n.floor=p_origin_floor AND n.area_id=origin_area AND n.ref_table='door_access_points'
    AND EXISTS (SELECT 1 FROM gp_nearby_edges e WHERE e.source=n.id OR e.target=n.id)
    AND g.geom IS NOT NULL AND fn_route_line_valid_inside_area(origin_area,g.geom,0.10);$sql$;
    new_anchor constant text := $sql$  WITH anchor_area AS MATERIALIZED (
    SELECT ST_MakeValid(a.geom) AS area
    FROM areas a WHERE a.id=origin_area AND a.floor=p_origin_floor
  ), anchor_buffers AS MATERIALIZED (
    SELECT area, ST_MakeValid(area) AS validation_area,
      ST_Buffer(area,-0.10,'join=mitre') AS core,
      ST_Buffer(area,0.0000001) AS boundary_cover
    FROM anchor_area
  ), anchor_geometry AS MATERIALIZED (
    SELECT b.*, ST_Buffer(core,0.0000001) AS visible_core,
      ST_Buffer(ST_Buffer(validation_area,-0.10,'join=mitre'),0.0000001) AS validation_core
    FROM anchor_buffers b
  )
  INSERT INTO gp_nearby_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT -1,n.id,GREATEST(ST_Length(g.geom)/speed,0.01),-1,p_origin_floor,p_origin_floor,
    g.geom,p_origin,n.geom,ST_Length(g.geom)/speed,ST_Length(g.geom)/speed,ST_Length(g.geom)
  FROM routing_nodes n CROSS JOIN anchor_geometry a CROSS JOIN LATERAL (
    SELECT public.fn_build_intra_area_edge_prepared(origin_area,p_origin_floor,p_origin,n.geom,0.10,
        a.area,a.core,a.visible_core,a.validation_area,a.validation_core,a.boundary_cover) AS geom
  ) g WHERE n.floor=p_origin_floor AND n.area_id=origin_area AND n.ref_table='door_access_points'
    AND EXISTS (SELECT 1 FROM gp_nearby_edges e WHERE e.source=n.id OR e.target=n.id)
    AND g.geom IS NOT NULL AND public.fn_route_line_inside_prepared(a.area,a.visible_core,g.geom,0.10);$sql$;
BEGIN

    FOREACH signature IN ARRAY ARRAY[destination_signature,origin_signature]
    LOOP
        definition := pg_get_functiondef(signature::regprocedure);
        graph_insert := '  INSERT INTO ' ||
            CASE WHEN signature=origin_signature THEN 'gp_origin_edges' ELSE 'gp_nearby_edges' END ||
            E'(source,target,cost,reverse_cost,ref_edge,door_ref,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)\n  SELECT e.src,e.dst';

        IF strpos(definition,new_private_filter)>0 THEN
            definition := pg_temp.replace_endpoint_sql(definition,new_private_filter,old_private_filter);
            definition := pg_temp.replace_endpoint_sql(definition,private_cte || graph_insert,graph_insert);
        ELSIF strpos(definition,old_private_filter)=0 THEN
            RAISE EXCEPTION 'Unknown private-area graph filter in %',signature;
        END IF;

        IF signature=origin_signature THEN
            IF strpos(definition,'  ), search AS MATERIALIZED (')>0 THEN
                definition := pg_temp.replace_endpoint_sql(definition,'  ), search AS MATERIALIZED (','  ), search AS (');
            ELSIF strpos(definition,'  ), search AS (')=0 THEN
                RAISE EXCEPTION 'Unknown nearby-origin search block';
            END IF;
        ELSIF strpos(definition,new_anchor)>0 THEN
            definition := pg_temp.replace_endpoint_sql(definition,new_anchor,old_anchor);
        ELSIF strpos(definition,old_anchor)=0 THEN
            RAISE EXCEPTION 'Unknown nearby-destination anchor block';
        END IF;
        EXECUTE definition;
    END LOOP;
END;
$migration$;
DROP FUNCTION IF EXISTS public.fn_build_intra_area_edge_prepared(bigint,smallint,geometry,geometry,double precision,geometry,geometry,geometry,geometry,geometry,geometry);
DROP FUNCTION IF EXISTS public.fn_route_visibility_prepared(bigint,geometry,geometry,geometry,geometry);
DROP FUNCTION IF EXISTS public.fn_route_line_inside_prepared(geometry,geometry,geometry,double precision);
DROP FUNCTION pg_temp.replace_endpoint_sql(text,text,text,integer);
COMMIT;
