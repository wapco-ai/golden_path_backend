BEGIN;

CREATE TABLE public.routing_timing_settings (
    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
    walk_speed_mps double precision NOT NULL CHECK (walk_speed_mps > 0 AND walk_speed_mps < 'Infinity'::double precision)
);

INSERT INTO public.routing_timing_settings (walk_speed_mps) VALUES (1.2);

CREATE FUNCTION public.fn_routing_walk_speed() RETURNS double precision
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog
AS $speed$
    SELECT COALESCE((SELECT walk_speed_mps FROM public.routing_timing_settings WHERE singleton), 1.2);
$speed$;

GRANT EXECUTE ON FUNCTION public.fn_routing_walk_speed() TO PUBLIC;

DO $migration$
DECLARE
    patch record;
    definition text;
BEGIN
    FOR patch IN SELECT * FROM (VALUES
        ('public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry,integer)'::regprocedure, 'ELSE 1.2 END', 'ELSE public.fn_routing_walk_speed() END', 1),
        ('public.fn_route_nearby_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)'::regprocedure, 'ELSE 1.2 END', 'ELSE public.fn_routing_walk_speed() END', 1),
        ('public.fn_route_nearby_origin_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)'::regprocedure, 'ELSE 1.2 END', 'ELSE public.fn_routing_walk_speed() END', 1),
        ('public.fn_same_floor_stair_access(bigint,timestamptz,public.gender_enum,text)'::regprocedure, ' * 1.2', ' * public.fn_routing_walk_speed()::numeric', 2)
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

COMMIT;
