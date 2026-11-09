-- Purpose: serve the public engineering map for floor -1 as vector tiles.
-- Dependencies: public.areas, public.i18n_texts, public.lang_enum, PostGIS.
-- Object: public.fn_public_engineering_map_mvt(integer,integer,integer,smallint,public.lang_enum).
-- Routing impact: display only. Does not read or change routing data.
-- Rollback: apply the matching down migration.

BEGIN;

CREATE OR REPLACE FUNCTION public.fn_public_engineering_map_mvt(
  z integer,
  x integer,
  y integer,
  p_floor smallint DEFAULT -1,
  p_lang public.lang_enum DEFAULT 'fa'
) RETURNS bytea
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  tile_bbox_3857 public.geometry;
  tile_bbox_32640 public.geometry;
BEGIN
  IF p_floor IS DISTINCT FROM -1 THEN
    RETURN ''::bytea;
  END IF;

  tile_bbox_3857 := public.ST_TileEnvelope(z, x, y);
  tile_bbox_32640 := public.ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT public.ST_AsMVT(tile, 'engineering_areas', 4096, 'geom')
    FROM (
      SELECT
        public.ST_AsMVTGeom(public.ST_Transform(a.geom, 3857), tile_bbox_3857, 4096, 64, true) AS geom,
        a.area_type::text AS area_type,
        NULLIF(btrim(i.txt), '') AS label,
        CASE WHEN a.is_closed THEN 'closed' ELSE 'public' END AS appearance_class,
        CASE
          WHEN a.attrs #>> '{operational,is_covered}' IN ('true', '1') THEN true
          WHEN a.attrs #>> '{operational,is_covered}' IN ('false', '0') THEN false
          ELSE CASE btrim(translate(a.attrs #>> '{source_properties,indoor}', U&'\064A\0643', U&'\06CC\06A9'))
            WHEN U&'\0633\0631\067E\0648\0634\06CC\062F\0647' THEN true
            WHEN U&'\0631\0648\0628\0627\0632' THEN false
          END
        END AS is_covered
      FROM public.areas AS a
      LEFT JOIN public.i18n_texts AS i
        ON i.entity_table = 'areas'
        AND i.entity_id = a.id
        AND i.field = 'name'
        AND i.lang = p_lang
      WHERE a.floor = -1
        AND a.geom OPERATOR(public.&&) tile_bbox_32640
        AND public.ST_Intersects(a.geom, tile_bbox_32640)
        AND CASE
          WHEN a.attrs #>> '{access_policy,public_access}' IN ('true', '1') THEN true
          WHEN a.attrs #>> '{access_policy,public_access}' IN ('false', '0') THEN false
          ELSE btrim(translate(COALESCE(
            a.attrs #>> '{source_properties,owner}',
            a.attrs #>> '{source_properties,Noefaali_1}',
            a.attrs ->> 'owner',
            a.attrs #>> '{classification,access_class}'
          ), U&'\064A\0643', U&'\06CC\06A9')) IN (U&'\0639\0645\0648\0645\06CC', 'public')
        END
    ) AS tile
    WHERE tile.geom IS NOT NULL
  );
END;
$$;

COMMIT;
