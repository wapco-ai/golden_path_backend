BEGIN;

INSERT INTO public.routing_floors (floor, label, sort_order)
VALUES (1, 'Engineering test floor', 1) ON CONFLICT DO NOTHING;

-- Identical XY on three floors must never leak another floor's geometry.
WITH fixtures (floor, is_closed, attrs, label) AS (
  VALUES
    (-1, false, '{"access_policy":{"public_access":true}}'::jsonb, 'EngineeringBasementOnly'),
    (0, false, '{"access_policy":{"public_access":true},"secret_fixture":"engineering-sensitive-value"}'::jsonb, 'EngineeringGroundOnly'),
    (1, false, '{"access_policy":{"public_access":true}}'::jsonb, 'EngineeringUpperOnly'),
    (0, true, '{"access_policy":{"public_access":true}}'::jsonb, 'EngineeringGroundClosed'),
    (0, false, '{"access_policy":{"public_access":false},"source_properties":{"owner":"public"}}'::jsonb, 'EngineeringGroundPrivate'),
    (0, false, '{}'::jsonb, 'EngineeringGroundUnknown')
), inserted AS (
  INSERT INTO public.areas (geom, area_type, floor, is_closed, attrs)
  SELECT ST_Multi(ST_MakeEnvelope(500000, 4000000, 500020, 4000020, 32640)),
    'courtyard', floor, is_closed, attrs FROM fixtures
  RETURNING id, floor, is_closed, attrs
)
INSERT INTO public.i18n_texts (entity_table, entity_id, field, lang, txt)
SELECT 'areas', inserted.id, 'name', 'fa', fixtures.label
FROM inserted JOIN fixtures USING (floor, is_closed, attrs);

CREATE ROLE engineering_map_floors_test NOLOGIN;
SET LOCAL ROLE engineering_map_floors_test;

DO $$
DECLARE
  center geometry := ST_Transform(ST_SetSRID(ST_MakePoint(500010, 4000010), 32640), 3857);
  x integer := floor((ST_X(center) + 20037508.342789244) / 611.49622628141);
  y integer := floor((20037508.342789244 - ST_Y(center)) / 611.49622628141);
  requested_floor smallint;
  tile bytea;
  expected text;
BEGIN
  FOR requested_floor IN -1..1 LOOP
    tile := public.fn_public_engineering_map_mvt(16, x, y, requested_floor::smallint, 'fa');
    expected := CASE requested_floor WHEN -1 THEN 'EngineeringBasementOnly'
      WHEN 0 THEN 'EngineeringGroundOnly' ELSE 'EngineeringUpperOnly' END;
    IF position(expected::bytea IN tile) = 0 THEN
      RAISE EXCEPTION 'Missing geometry on floor %', requested_floor;
    END IF;
    IF (requested_floor <> -1 AND position('EngineeringBasementOnly'::bytea IN tile) > 0)
      OR (requested_floor <> 0 AND position('EngineeringGroundOnly'::bytea IN tile) > 0)
      OR (requested_floor <> 1 AND position('EngineeringUpperOnly'::bytea IN tile) > 0)
      OR position('EngineeringGroundPrivate'::bytea IN tile) > 0
      OR position('EngineeringGroundUnknown'::bytea IN tile) > 0
      OR position('engineering-sensitive-value'::bytea IN tile) > 0 THEN
      RAISE EXCEPTION 'Engineering tile exposed another floor or non-public attributes';
    END IF;
    IF requested_floor = 0 AND (position('EngineeringGroundClosed'::bytea IN tile) = 0
      OR position('closed'::bytea IN tile) = 0) THEN
      RAISE EXCEPTION 'Closed public areas lost their existing appearance contract';
    END IF;
  END LOOP;
  IF octet_length(public.fn_public_engineering_map_mvt(16, x, y, NULL::smallint)) <> 0
    OR octet_length(public.fn_public_engineering_map_mvt(16, x, y, 32767::smallint)) <> 0 THEN
    RAISE EXCEPTION 'Unknown or empty floor must return no geometry';
  END IF;
END;
$$;

ROLLBACK;
