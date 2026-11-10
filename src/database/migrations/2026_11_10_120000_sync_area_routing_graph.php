<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.fn_sync_area_routing_graph(p_area_id bigint) RETURNS jsonb
            LANGUAGE plpgsql STRICT SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp
            AS $function$
            DECLARE
                v_floor smallint;
                v_role text;
                v_summary jsonb;
                v_edge_ids bigint[];
                v_status text;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('routing-area:' || p_area_id::text, 0));

                SELECT a.floor, public.fn_area_routing_role(a.area_type, a.attrs)
                INTO v_floor, v_role
                FROM public.areas a
                WHERE a.id = p_area_id;

                IF v_role = 'routable' THEN
                    -- The visibility builder creates transaction-local cache tables.
                    -- Never let it reuse temporary relations supplied by the caller.
                    IF EXISTS (SELECT 1 FROM pg_catalog.pg_class WHERE relnamespace = pg_my_temp_schema()) THEN
                        RAISE EXCEPTION 'Area graph sync requires a clean temporary schema';
                    END IF;

                    v_summary := public.fn_rebuild_graph_for_area(p_area_id, v_floor, false, 2.50, 0.20);
                    v_status := CASE
                        WHEN NOT COALESCE((v_summary->>'ok')::boolean, false) THEN 'incomplete'
                        WHEN (v_summary->>'inserted_edges')::integer > 0 THEN 'ready'
                        ELSE 'insufficient_access_points'
                    END;
                ELSIF v_role = 'doorstep_only' THEN
                    SELECT array_agg(e.id) INTO v_edge_ids
                    FROM public.routing_nodes s
                    JOIN public.routing_edges_static e ON e.src = s.id
                    JOIN public.routing_nodes t ON t.id = e.dst
                    WHERE s.area_id = p_area_id AND t.area_id = p_area_id
                      AND e.floor = v_floor AND e.door_id IS NULL
                      AND COALESCE(e.attrs->>'edge_type', 'intra_area') = 'intra_area';

                    DELETE FROM public.routing_edge_areas WHERE edge_id = ANY(v_edge_ids);
                    DELETE FROM public.routing_edges_static WHERE id = ANY(v_edge_ids);
                    v_status := 'entrance_only';
                ELSE
                    v_status := 'incomplete';
                END IF;

                RETURN jsonb_build_object('status', v_status, 'role', v_role);
            END;
            $function$;

            REVOKE ALL ON FUNCTION public.fn_sync_area_routing_graph(bigint) FROM PUBLIC;

            DO $grant$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gp_app_runtime') THEN
                    IF has_schema_privilege('gp_app_runtime', 'public', 'CREATE') THEN
                        RAISE EXCEPTION 'The application role must not be able to create objects in public';
                    END IF;
                    GRANT EXECUTE ON FUNCTION public.fn_sync_area_routing_graph(bigint) TO gp_app_runtime;
                END IF;
            END;
            $grant$;
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION public.fn_sync_area_routing_graph(bigint)');
    }
};
