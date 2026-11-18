BEGIN;
DO $migration$
DECLARE
    signature regprocedure := 'public.fn_route_nearby_origin_edges(timestamptz,gender_enum,text,smallint,smallint,geometry,geometry)'::regprocedure;
    definition text := pg_get_functiondef(signature);
    marker text;
    first_pos integer;
    last_pos integer;
    last_mark text;
BEGIN
    FOREACH marker IN ARRAY ARRAY['nearby endpoint restrictions','snapped-origin area anchors'] LOOP
        first_pos := strpos(definition,'  -- BEGIN ' || marker);
        last_mark := '  -- END ' || marker || E'\n';
        last_pos := strpos(definition,last_mark);
        IF first_pos=0 AND last_pos=0 THEN CONTINUE; END IF;
        IF first_pos=0 OR last_pos<=first_pos THEN RAISE EXCEPTION 'Incomplete % block',marker; END IF;
        definition := overlay(definition placing '' from first_pos for last_pos+length(last_mark)-first_pos);
    END LOOP;
    EXECUTE definition;
END $migration$;
COMMIT;
