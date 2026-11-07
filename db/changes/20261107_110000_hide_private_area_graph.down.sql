-- Purpose: restore the previous unfiltered routing graph tile.
-- Dependencies: the matching up migration and PostGIS.
-- Object: public.fn_routing_edges_static_mvt(integer,integer,integer,smallint).
-- Routing impact: display only.
-- Rollback: reapply the matching up migration.

BEGIN;

CREATE OR REPLACE FUNCTION public.fn_routing_edges_static_mvt(z integer, x integer, y integer, p_floor smallint DEFAULT NULL::smallint) RETURNS bytea
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
  tile_bbox_3857  geometry;
  tile_bbox_32640 geometry;
BEGIN
  tile_bbox_3857  := ST_TileEnvelope(z, x, y);
  tile_bbox_32640 := ST_Transform(tile_bbox_3857, 32640);

  RETURN (
    SELECT ST_AsMVT(t, 'routing_edges_static', 4096, 'geom')
    FROM (
      SELECT
        ST_AsMVTGeom(
          ST_Transform(e.geom, 3857),
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
        AND e.geom && tile_bbox_32640
        AND ST_Intersects(e.geom, tile_bbox_32640)
    ) AS t
    WHERE t.geom IS NOT NULL
  );
END;
$$;

COMMIT;
