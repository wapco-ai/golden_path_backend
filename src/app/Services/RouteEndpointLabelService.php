<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RouteEndpointLabelService
{
    public function find(float $lat, float $lon, int $floor, string $lang, ?int $edgeId = null): ?array
    {
        $label = DB::selectOne(<<<'SQL'
            WITH input AS (
                SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?::float8, ?::float8), 4326), 32640) AS geom,
                    ?::smallint AS floor, ?::lang_enum AS lang, ?::bigint AS edge_id
            ), containing AS MATERIALIZED (
                SELECT a.id, a.geom, a.attrs, ST_Area(a.geom) AS size,
                    ST_Contains(a.geom, i.geom)
                        AND (i.edge_id IS NULL OR NOT ST_DWithin(ST_Boundary(a.geom), i.geom, 0.01)) AS inside,
                    NULLIF(btrim(fn_i18n_label('areas', a.id, 'name', i.lang, 'fa')), '') AS name
                FROM input i JOIN areas a ON a.floor = i.floor
                    AND (ST_Covers(a.geom, i.geom) OR (i.edge_id IS NOT NULL AND ST_DWithin(a.geom, i.geom, 0.01)))
            ), public_areas AS (
                SELECT * FROM containing
                WHERE (attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
                  AND NOT EXISTS (
                    SELECT 1 FROM containing a WHERE a.inside AND a.attrs #>> '{access_policy,public_access}' = 'false'
                  )
            ), edge_access AS (
                SELECT n.ref_id AS access_id
                FROM input i JOIN routing_edges_static e ON e.id = i.edge_id
                JOIN routing_nodes n ON n.id IN (e.src, e.dst)
                    AND n.ref_table = 'door_access_points' AND n.floor = i.floor
                    AND ST_DWithin(n.geom, i.geom, 0.05)
            ), doors_near AS (
                SELECT d.id, d.geom, ST_Distance(d.geom, i.geom) AS distance,
                    NULLIF(btrim(fn_i18n_label('doors', d.door_id, 'name', i.lang, 'fa')), '') AS name,
                    EXISTS (SELECT 1 FROM edge_access e WHERE e.access_id = d.id) AS route_match
                FROM input i JOIN door_access_points d ON d.floor = i.floor
                    AND ST_DWithin(d.geom, i.geom, 12)
                WHERE NOT COALESCE(d.needs_review, false)
                  AND (
                    EXISTS (
                        SELECT 1 FROM public_areas a WHERE a.id IN (d.from_area, d.to_area)
                        AND ST_Covers(a.geom, ST_MakeLine(i.geom, d.geom))
                    )
                    OR EXISTS (SELECT 1 FROM edge_access e WHERE e.access_id = d.id)
                  )
            ), candidates AS (
                SELECT id, name, 'door' AS kind, (NOT route_match AND distance > 1.5) AS nearby,
                    CASE WHEN route_match THEN 0 WHEN distance <= 1.5 THEN 1 ELSE 3 END AS priority,
                    distance AS rank
                FROM doors_near
                UNION ALL
                SELECT id, name, 'area', false, 2, size FROM public_areas
                UNION ALL
                SELECT p.id, NULLIF(btrim(fn_i18n_label('poi_points', p.id, 'name', i.lang, 'fa')), ''),
                    'poi', true, 3, ST_Distance(p.geom, i.geom)
                FROM input i JOIN poi_points p ON p.floor = i.floor AND ST_DWithin(p.geom, i.geom, 12)
                WHERE EXISTS (
                    SELECT 1 FROM public_areas a WHERE ST_Covers(a.geom, ST_MakeLine(i.geom, p.geom))
                )
            )
            SELECT id, name, kind, nearby FROM candidates
            WHERE name IS NOT NULL
            ORDER BY priority, rank, kind, id LIMIT 1
            SQL, [$lon, $lat, $floor, $lang, $edgeId]);

        return $label ? [
            'id' => (string) $label->id,
            'name' => $label->name,
            'kind' => $label->kind,
            'nearby' => (bool) $label->nearby,
        ] : null;
    }
}
