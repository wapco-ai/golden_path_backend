-- Purpose: grant the graph queue worker the permissions used to rebuild and validate door landings.
-- Dependencies: the baseline and 20260915_160000_multifloor_connectors. The queue role
-- and the newer visibility helpers are optional. Queue storage and extension ACLs are prerequisites.
-- Objects: schema, temporary tables, graph relations, ID sequences and graph functions.
-- Routing impact: grants permissions to gp_queue_runtime without changing the graph or its geometry.
-- Rollback: restore the captured pre-deployment ACLs to preserve any pre-existing grants.

BEGIN;

DO $grant$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gp_queue_runtime') THEN
        GRANT USAGE ON SCHEMA public TO gp_queue_runtime;
        EXECUTE format('GRANT TEMPORARY ON DATABASE %I TO gp_queue_runtime', current_database());

        GRANT SELECT ON public.areas, public.doors, public.door_access_points,
            public.routing_connector_stops, public.routing_connector_nodes TO gp_queue_runtime;
        GRANT UPDATE (attrs) ON public.doors TO gp_queue_runtime;

        GRANT SELECT, INSERT, DELETE ON public.routing_nodes, public.routing_edges_static,
            public.mesh_triangles, public.mesh_adjacency TO gp_queue_runtime;
        GRANT SELECT, DELETE ON public.routing_edge_areas TO gp_queue_runtime;
        GRANT USAGE ON SEQUENCE public.routing_nodes_id_seq, public.routing_edges_static_id_seq,
            public.mesh_triangles_id_seq, public.mesh_adjacency_id_seq TO gp_queue_runtime;

        GRANT EXECUTE ON FUNCTION
            public.fn_rebuild_graph_for_door(bigint, boolean, double precision, double precision),
            public.fn_rebuild_graph_for_area(bigint, smallint, boolean, double precision, double precision),
            public.fn_area_routing_role(public.area_type_enum, jsonb),
            public.fn_rebuild_area_mesh_grid(bigint, smallint, double precision, double precision),
            public.fn_clear_area_mesh(bigint, smallint),
            public.fn_dap_node_geom_inside_area(public.geometry, bigint, double precision),
            public.fn_build_intra_area_edge_geom(bigint, smallint, public.geometry, public.geometry, double precision),
            public.fn_route_line_valid_inside_area(bigint, public.geometry, double precision),
            public.fn_route_smooth_polyline_inside_area(bigint, public.geometry, double precision),
            public.fn_repair_isolated_dap_nodes_for_area(bigint, smallint, double precision),
            public.fn_route_line_valid_inside_area_dap_connector(bigint, public.geometry, double precision, double precision)
        TO gp_queue_runtime;

        IF to_regprocedure('public.fn_route_line_inside_geom(public.geometry,public.geometry,double precision)') IS NOT NULL THEN
            GRANT EXECUTE ON FUNCTION public.fn_route_line_inside_geom(public.geometry, public.geometry, double precision)
                TO gp_queue_runtime;
        END IF;
        IF to_regprocedure('public.fn_route_visibility_inside_area(bigint,smallint,public.geometry,public.geometry,double precision)') IS NOT NULL THEN
            GRANT EXECUTE ON FUNCTION
                public.fn_route_visibility_inside_area(bigint, smallint, public.geometry, public.geometry, double precision)
                TO gp_queue_runtime;
        END IF;
    END IF;
END;
$grant$;

COMMIT;
