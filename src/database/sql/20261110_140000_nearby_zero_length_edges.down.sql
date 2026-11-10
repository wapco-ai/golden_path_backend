-- Restore the previous nearby route clipping rules.
-- Requires nearby origin and destination routing and PostGIS.
-- Changes the temporary endpoint geometry in the two nearby routing functions.
BEGIN;

DO $migration$
DECLARE
    patch record;
    definition text;
BEGIN
    FOR patch IN SELECT * FROM (VALUES
        ('public.fn_route_nearby_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)'::regprocedure,
         'WHEN terminal.start_fraction=terminal.fraction OR ST_Length(terminal.geom)=0 THEN', 'WHEN terminal.start_fraction=terminal.fraction THEN'),
        ('public.fn_route_nearby_origin_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)'::regprocedure,
         'WHEN l.from_fraction=l.to_fraction OR ST_Length(l.geom)=0 THEN', 'WHEN l.from_fraction=l.to_fraction THEN')
    ) AS changes(function_id, original, replacement)
    LOOP
        definition := pg_get_functiondef(patch.function_id);
        IF length(definition) - length(replace(definition, patch.original, '')) <> length(patch.original) THEN
            RAISE EXCEPTION 'Unexpected clipping rule in %', patch.function_id;
        END IF;
        EXECUTE replace(definition, patch.original, patch.replacement);
    END LOOP;
END;
$migration$;

COMMIT;
