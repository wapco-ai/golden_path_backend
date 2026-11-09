BEGIN;

SELECT public.fn_patch_same_floor_stair_route_sql(false);
DROP FUNCTION public.fn_patch_same_floor_stair_route_sql(boolean);

DROP TRIGGER same_floor_stair_edge_removed ON public.routing_edges_static;
DROP TRIGGER same_floor_stair_area_changed ON public.areas;
DROP TRIGGER same_floor_stair_node_changed ON public.routing_nodes;
DROP TRIGGER same_floor_stair_access_changed ON public.door_access_points;
DROP TRIGGER same_floor_stair_landing_removed ON public.same_floor_stairs;
DROP TRIGGER same_floor_stair_definition_changed ON public.same_floor_stairs;

DELETE FROM public.routing_edges_static WHERE attrs->>'edge_type' = 'same_floor_stair';
DROP INDEX public.routing_edges_static_same_floor_stair_id_idx;
DROP INDEX public.routing_edges_static_same_floor_stair_key;

DROP FUNCTION public.fn_same_floor_stair_edge_removed();
DROP FUNCTION public.fn_same_floor_stair_area_changed();
DROP FUNCTION public.fn_same_floor_stair_access_changed();
DROP FUNCTION public.fn_same_floor_stair_landing_removed();
DROP FUNCTION public.fn_same_floor_stair_definition_changed();
DROP FUNCTION public.fn_refresh_same_floor_stair(bigint);
DROP FUNCTION public.fn_same_floor_stair_landing_areas(smallint);
DROP FUNCTION public.fn_same_floor_stair_access(bigint,timestamptz,public.gender_enum,text);
DROP FUNCTION public.fn_same_floor_stair_edge_geom(bigint);
DROP FUNCTION public.fn_same_floor_stair_candidate(bigint,smallint,bigint,bigint,bigint,bigint);
DROP TABLE public.same_floor_stairs;

COMMIT;
