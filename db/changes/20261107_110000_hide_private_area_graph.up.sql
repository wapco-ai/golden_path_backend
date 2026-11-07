-- Purpose: hide graph edges with an endpoint in a private area in the admin map.
-- Dependencies: public.routing_edges_static, public.routing_nodes, public.areas, PostGIS.
-- Object: public.fn_routing_edges_static_mvt(integer,integer,integer,smallint).
-- Routing impact: display only, preserves stored edges and route geometry.
-- The tiles role can execute this function without read access to routing_nodes.
-- Rollback: apply the matching down migration.

BEGIN;

CREATE OR REPLACE FUNCTION public.fn_routing_edges_static_mvt(z integer, x integer, y integer, p_floor smallint DEFAULT NULL::smallint) RETURNS bytea
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, pg_temp
    AS $$
DECLARE
  tile_bbox_3857  public.geometry;
  tile_bbox_32640 public.geometry;
BEGIN
  tile_bbox_3857  := public.ST_TileEnvelope(z, x, y);
  tile_bbox_32640 := public.ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT public.ST_AsMVT(t, 'routing_edges_static', 4096, 'geom')
    FROM (
      SELECT
        public.ST_AsMVTGeom(
          public.ST_Transform(e.geom, 3857),
          tile_bbox_3857,
          4096,
          64,
          true
        ) AS geom,

        e.id,
        e.floor,
        e.src,
        e.dst,
        e.base_cost,
        e.door_id,

        COALESCE(e.attrs->>'edge_type', 'intra_area') AS edge_type,
        COALESCE(e.attrs->>'source', 'routing_edges_static') AS source,
        e.attrs->>'access_id' AS access_id,
        e.attrs->>'from_area' AS from_area,
        e.attrs->>'to_area' AS to_area,
        e.attrs

      FROM public.routing_edges_static e
      WHERE
        (p_floor IS NULL OR e.floor = p_floor)
        AND e.geom OPERATOR(public.&&) tile_bbox_32640
        AND public.ST_Intersects(e.geom, tile_bbox_32640)
        AND NOT EXISTS (
          SELECT 1
          FROM public.routing_nodes n
          JOIN public.areas a ON a.id = n.area_id
          WHERE n.id IN (e.src, e.dst)
            AND CASE a.attrs #>> '{access_policy,public_access}'
              WHEN 'false' THEN true
              WHEN '0' THEN true
              WHEN 'true' THEN false
              WHEN '1' THEN false
              ELSE NULLIF(btrim(translate(COALESCE(
                a.attrs #>> '{source_properties,owner}',
                a.attrs #>> '{source_properties,Noefaali_1}',
                a.attrs ->> 'owner',
                a.attrs #>> '{classification,access_class}'
              ), U&'\064A\0643', U&'\06CC\06A9')), '') NOT IN (U&'\0639\0645\0648\0645\06CC', 'public')
            END
        )
    ) AS t
    WHERE t.geom IS NOT NULL
  );
END;
$$;

COMMIT;
