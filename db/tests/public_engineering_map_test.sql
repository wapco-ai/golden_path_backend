BEGIN;

WITH fixtures (floor, is_closed, attrs, label) AS (
  VALUES
    (-1, false, '{"access_policy":{"public_access":true},"operational":{"is_covered":true},"secret_fixture":"engineering-sensitive-value"}'::jsonb, 'EngineeringPublicFixture'),
    (-1, true, jsonb_build_object('source_properties', jsonb_build_object('owner', U&'\0639\0645\0648\0645\064A', 'indoor', U&'\0631\0648\0628\0627\0632')), 'EngineeringClosedFixture'),
    (-1, false, '{"access_policy":{"public_access":false},"source_properties":{"owner":"public"}}'::jsonb, 'EngineeringPrivateFixture'),
    (-1, false, '{}'::jsonb, 'EngineeringUnknownFixture'),
    (0, false, '{"access_policy":{"public_access":true}}'::jsonb, 'EngineeringGroundFixture')
), inserted AS (
  INSERT INTO public.areas (geom, area_type, floor, is_closed, attrs)
  SELECT ST_Multi(ST_MakeEnvelope(500000, 4000000, 500020, 4000020, 32640)),
    'courtyard', floor, is_closed, attrs
  FROM fixtures
  RETURNING id, floor, is_closed, attrs
)
INSERT INTO public.i18n_texts (entity_table, entity_id, field, lang, txt)
SELECT 'areas', inserted.id, 'name', 'fa', fixtures.label
FROM inserted JOIN fixtures USING (floor, is_closed, attrs);

CREATE ROLE public_engineering_map_tile_test NOLOGIN;
SET LOCAL ROLE public_engineering_map_tile_test;

DO $$
DECLARE
  center geometry := ST_Transform(ST_SetSRID(ST_MakePoint(500010, 4000010), 32640), 3857);
  x integer := floor((ST_X(center) + 20037508.342789244) / 611.49622628141);
  y integer := floor((20037508.342789244 - ST_Y(center)) / 611.49622628141);
  tile bytea := public.fn_public_engineering_map_mvt(16, x, y, -1::smallint);
BEGIN
  IF position('engineering_areas'::bytea IN tile) = 0
    OR position('EngineeringPublicFixture'::bytea IN tile) = 0
    OR position('EngineeringClosedFixture'::bytea IN tile) = 0
    OR position('appearance_class'::bytea IN tile) = 0
    OR position('is_covered'::bytea IN tile) = 0 THEN
    RAISE EXCEPTION 'Missing public engineering geometry or display properties';
  END IF;

  IF position('EngineeringPrivateFixture'::bytea IN tile) > 0
    OR position('EngineeringUnknownFixture'::bytea IN tile) > 0
    OR position('EngineeringGroundFixture'::bytea IN tile) > 0
    OR position('engineering-sensitive-value'::bytea IN tile) > 0 THEN
    RAISE EXCEPTION 'Public engineering tile exposed data outside its display contract';
  END IF;

  IF octet_length(public.fn_public_engineering_map_mvt(16, x, y, 0::smallint)) <> 0
    OR octet_length(public.fn_public_engineering_map_mvt(16, x, y, NULL::smallint)) <> 0 THEN
    RAISE EXCEPTION 'Public engineering tile returned geometry for a floor other than -1';
  END IF;
END;
$$;

ROLLBACK;
