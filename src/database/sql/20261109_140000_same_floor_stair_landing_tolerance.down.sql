BEGIN;

DO $migration$
DECLARE
    definition text;
    original text := 'ST_DWithin(la.geom, ns.geom, 0.001) AND ST_DWithin(lb.geom, ne.geom, 0.001)';
    replacement text := 'ST_Covers(la.geom, ns.geom) AND ST_Covers(lb.geom, ne.geom)';
BEGIN
    SELECT pg_get_functiondef(
        'public.fn_same_floor_stair_candidate(bigint,smallint,bigint,bigint,bigint,bigint)'::regprocedure
    ) INTO definition;
    IF length(definition) - length(replace(definition, original, '')) <> length(original) THEN
        RAISE EXCEPTION 'Unexpected same-floor stair landing validator definition';
    END IF;
    EXECUTE replace(definition, original, replacement);
    PERFORM public.fn_refresh_same_floor_stair(s.id) FROM public.same_floor_stairs s WHERE s.is_active ORDER BY s.id;
END;
$migration$;

COMMIT;
