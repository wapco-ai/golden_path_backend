-- Allow sub-millimetre coordinate roundoff at a registered stair landing.
-- The DAP-derived line and footprint coverage check remain exact.
BEGIN;

DO $migration$
DECLARE
    definition text;
    original text := 'ST_Covers(la.geom, ns.geom) AND ST_Covers(lb.geom, ne.geom)';
    replacement text := 'ST_DWithin(la.geom, ns.geom, 0.001) AND ST_DWithin(lb.geom, ne.geom, 0.001)';
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
