-- Reuse the installed multifloor solver with optional exact DAP-backed endpoint nodes.
-- Existing coordinate calls and stored graph data are unaffected.
BEGIN;

DO $migration$
DECLARE
    definition text := pg_get_functiondef(
        'public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry,integer)'::regprocedure
    );
    edit record;
BEGIN
    FOR edit IN SELECT * FROM (VALUES
        ('p_k integer DEFAULT 1)', 'p_k integer, p_origin_node bigint, p_dest_node bigint)'),
        ('IF origin_area IS NULL OR dest_area IS NULL THEN RETURN; END IF;',
         $sql$IF p_origin_node IS NOT NULL THEN
    SELECT n.area_id,n.geom INTO origin_area,p_origin
    FROM public.routing_nodes n WHERE n.id=p_origin_node AND n.floor=p_origin_floor AND n.ref_table='door_access_points';
  END IF;
  IF p_dest_node IS NOT NULL THEN
    SELECT n.area_id,n.geom INTO dest_area,p_dest
    FROM public.routing_nodes n WHERE n.id=p_dest_node AND n.floor=p_dest_floor AND n.ref_table='door_access_points';
  END IF;
  IF origin_area IS NULL OR dest_area IS NULL THEN RETURN; END IF;$sql$),
        ($sql$) g WHERE n.floor=p_origin_floor AND n.area_id=origin_area AND n.ref_table='door_access_points'$sql$,
         $sql$) g WHERE p_origin_node IS NULL AND n.floor=p_origin_floor AND n.area_id=origin_area AND n.ref_table='door_access_points'$sql$),
        ($sql$) g WHERE n.floor=p_dest_floor AND n.area_id=dest_area AND n.ref_table='door_access_points'$sql$,
         $sql$) g WHERE p_dest_node IS NULL AND n.floor=p_dest_floor AND n.area_id=dest_area AND n.ref_table='door_access_points'$sql$),
        ('IF p_origin_floor=p_dest_floor AND origin_area=dest_area THEN',
         $sql$-- Attach the selected node only when it has a legal outgoing/incoming edge.
  INSERT INTO mf_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT -1,p_origin_node,0.01,-1,p_origin_floor,p_origin_floor,ST_MakeLine(p_origin,p_origin),p_origin,p_origin,0,0,0
  WHERE EXISTS (SELECT 1 FROM mf_edges e WHERE (e.source=p_origin_node AND e.cost>0) OR (e.target=p_origin_node AND e.reverse_cost>0));

  INSERT INTO mf_edges(source,target,cost,reverse_cost,floor_a,floor_b,geom,a,b,duration,reverse_duration,distance)
  SELECT p_dest_node,-2,0.01,-1,p_dest_floor,p_dest_floor,ST_MakeLine(p_dest,p_dest),p_dest,p_dest,0,0,0
  WHERE EXISTS (SELECT 1 FROM mf_edges e WHERE (e.target=p_dest_node AND e.cost>0) OR (e.source=p_dest_node AND e.reverse_cost>0));

  IF p_origin_node IS NULL AND p_dest_node IS NULL AND p_origin_floor=p_dest_floor AND origin_area=dest_area THEN$sql$)
    ) AS edits(original,replacement)
    LOOP
        IF length(definition)-length(replace(definition,edit.original,'')) <> length(edit.original) THEN
            RAISE EXCEPTION 'Unexpected multifloor routing definition at %', edit.original;
        END IF;
        definition := replace(definition,edit.original,edit.replacement);
    END LOOP;
    EXECUTE definition;
END;
$migration$;

REVOKE EXECUTE ON FUNCTION public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,geometry,geometry,integer,bigint,bigint) FROM PUBLIC;
DO $grants$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='gp_app_runtime') THEN
        GRANT EXECUTE ON FUNCTION public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,geometry,geometry,integer,bigint,bigint) TO gp_app_runtime;
    END IF;
END;
$grants$;

COMMIT;
