-- Existing installations may have created the exact-DAP overload before configurable timing.
BEGIN;
DO $migration$
DECLARE
    function_id regprocedure := to_regprocedure('public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry,integer,bigint,bigint)');
    definition text;
    original text := 'ELSE 1.2 END';
    replacement text := 'ELSE public.fn_routing_walk_speed() END';
BEGIN
    IF function_id IS NULL THEN RETURN; END IF;
    definition := pg_get_functiondef(function_id);
    IF length(definition) - length(replace(definition, original, '')) = length(original) THEN
        EXECUTE replace(definition, original, replacement);
    ELSIF length(definition) - length(replace(definition, replacement, '')) <> length(replacement) THEN
        RAISE EXCEPTION 'Unexpected exact-door walking-speed formula';
    END IF;
END;
$migration$;
COMMIT;
