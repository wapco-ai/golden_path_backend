-- Evaluate live connector access once per candidate floor and routing request.
BEGIN;
DO $migration$
DECLARE
    exact_signature text := 'public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry,integer)';
    exact_door_signature text := 'public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry,integer,bigint,bigint)';
    nearby_destination_signature text := 'public.fn_route_nearby_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)';
    nearby_origin_signature text := 'public.fn_route_nearby_origin_edges(timestamptz,public.gender_enum,text,smallint,smallint,public.geometry,public.geometry)';
    signature text;
    function_id regprocedure;
    definition text;
    privacy_filter text;
    source_block text;
    target_block text;
    source_template constant text := $block$  ), valid_stops AS (
    SELECT n.* FROM routing_connector_nodes n JOIN ready_connectors rc ON rc.id=n.connector_id
    JOIN LATERAL fn_allowed_doors(p_now,p_gender,p_mode,n.floor) d ON d.id=n.door_id AND d.is_allowed
    JOIN LATERAL fn_allowed_areas(p_now,p_gender,p_mode,n.floor) a ON a.id=n.area_id AND a.is_allowed@privacy@
    WHERE p_mode <> 'van' AND (p_mode <> 'wheelchair' OR n.kind NOT IN ('stair','escalator'))
  ), pairs AS ($block$;
    target_template constant text := $block$  ), stop_candidates AS MATERIALIZED (
    SELECT n.* FROM routing_connector_nodes n JOIN ready_connectors rc ON rc.id=n.connector_id
    WHERE p_mode <> 'van' AND (p_mode <> 'wheelchair' OR n.kind NOT IN ('stair','escalator'))
  ), stop_floors AS MATERIALIZED (
    SELECT DISTINCT floor FROM stop_candidates
  ), stop_allowed_doors AS MATERIALIZED (
    SELECT f.floor, d.id
    FROM stop_floors f
    CROSS JOIN LATERAL fn_allowed_doors(p_now,p_gender,p_mode,f.floor) d
    WHERE d.is_allowed
  ), stop_allowed_areas AS MATERIALIZED (
    SELECT f.floor, a.id
    FROM stop_floors f
    CROSS JOIN LATERAL fn_allowed_areas(p_now,p_gender,p_mode,f.floor) a
    WHERE a.is_allowed@privacy@
  ), valid_stops AS (
    SELECT n.*
    FROM stop_candidates n
    JOIN stop_allowed_doors d ON d.floor=n.floor AND d.id=n.door_id
    JOIN stop_allowed_areas a ON a.floor=n.floor AND a.id=n.area_id
  ), pairs AS ($block$;
BEGIN
    FOREACH signature IN ARRAY ARRAY[exact_signature, exact_door_signature, nearby_destination_signature, nearby_origin_signature]
    LOOP
        function_id := to_regprocedure(signature);
        IF function_id IS NULL THEN
            RAISE EXCEPTION 'Missing routing function %', signature;
        END IF;

        -- Nearby routes exclude private areas. Exact routes retain their existing access policy.
        privacy_filter := CASE WHEN signature IN (nearby_destination_signature, nearby_origin_signature)
            THEN E'\n      AND (a.attrs #>> ''{access_policy,public_access}'') IS DISTINCT FROM ''false'''
            ELSE ''
        END;
        source_block := replace(source_template, '@privacy@', privacy_filter);
        target_block := replace(target_template, '@privacy@', privacy_filter);
        definition := pg_get_functiondef(function_id);

        IF length(definition) - length(replace(definition, source_block, '')) = length(source_block)
            AND strpos(definition, target_block) = 0 THEN
            EXECUTE replace(definition, source_block, target_block);
        ELSIF strpos(definition, source_block) <> 0
            OR length(definition) - length(replace(definition, target_block, '')) <> length(target_block) THEN
            RAISE EXCEPTION 'Unexpected connector stop access block in %', signature;
        END IF;
    END LOOP;
END;
$migration$;
COMMIT;
