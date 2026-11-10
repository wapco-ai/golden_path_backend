BEGIN;

DO $migration$
DECLARE
    patch record;
    definition text;
BEGIN
    FOR patch IN SELECT * FROM (VALUES
        ('public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry,integer)'::regprocedure, 'ELSE public.fn_routing_walk_speed() END', 'ELSE 1.2 END', 1),
        ('public.fn_route_nearby_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)'::regprocedure, 'ELSE public.fn_routing_walk_speed() END', 'ELSE 1.2 END', 1),
        ('public.fn_route_nearby_origin_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)'::regprocedure, 'ELSE public.fn_routing_walk_speed() END', 'ELSE 1.2 END', 1),
        ('public.fn_same_floor_stair_access(bigint,timestamptz,public.gender_enum,text)'::regprocedure, ' * public.fn_routing_walk_speed()::numeric', ' * 1.2', 2)
    ) AS changes(function_id, original, replacement, occurrences)
    LOOP
        definition := pg_get_functiondef(patch.function_id);
        IF length(definition) - length(replace(definition, patch.original, '')) <> length(patch.original) * patch.occurrences THEN
            RAISE EXCEPTION 'Unexpected speed formula in %', patch.function_id;
        END IF;
        EXECUTE replace(definition, patch.original, patch.replacement);
    END LOOP;
END;
$migration$;

DROP FUNCTION public.fn_routing_walk_speed();
DROP TABLE public.routing_timing_settings;

COMMIT;
