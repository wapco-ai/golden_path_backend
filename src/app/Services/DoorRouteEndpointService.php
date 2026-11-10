<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DoorRouteEndpointService
{
    /** Eligibility on the chosen side of each DAP, using the live routing graph. */
    public function forAreas(array $areaIds, int $floor, string $mode, string $gender, string $lang, ?int $nearAccessId = null): array
    {
        return DB::select(<<<'SQL'
            WITH input AS (
                SELECT ?::jsonb AS area_ids, ?::smallint AS floor, ?::text AS mode,
                    ?::gender_enum AS gender, ?::lang_enum AS lang, ?::bigint AS near_id
            ), access_points AS MATERIALIZED (
                SELECT p.*, a.id AS area_id,
                    CASE WHEN p.from_area=a.id THEN p.to_area ELSE p.from_area END AS other_area_id
                FROM input i CROSS JOIN LATERAL jsonb_array_elements_text(i.area_ids) area(id)
                JOIN areas a ON a.id=area.id::bigint
                JOIN door_access_points p ON p.floor=a.floor AND (p.from_area=a.id OR p.to_area=a.id)
                WHERE a.floor=i.floor AND (a.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
            ), live_nodes AS MATERIALIZED (
                SELECT DISTINCT node.id
                FROM input i
                CROSS JOIN LATERAL fn_routing_edges_live_param(now(),i.gender,i.mode,i.floor) e
                CROSS JOIN LATERAL (VALUES (e.src),(e.dst)) node(id)
                WHERE e.cost>0 OR e.reverse_cost>0
            )
            SELECT p.id AS access_id, p.door_id, p.area_id, p.floor, n.id AS node_id,
                ST_Y(wgs.point) AS lat, ST_X(wgs.point) AS lon,
                fn_i18n_label('areas',p.other_area_id,'name',i.lang,'fa') AS name,
                ST_Distance(p.geom,nearby.geom) AS distance_m,
                CASE
                    WHEN d.is_open IS FALSE THEN 'closed'
                    WHEN d.is_allowed IS DISTINCT FROM true OR p.needs_review IS TRUE OR l.id IS NULL THEN 'unavailable'
                    ELSE 'available'
                END AS status
            FROM access_points p CROSS JOIN input i
            CROSS JOIN LATERAL (SELECT ST_Transform(p.geom,4326) AS point) wgs
            LEFT JOIN LATERAL fn_allowed_doors(now(),i.gender,i.mode,i.floor) d ON d.id=p.door_id
            LEFT JOIN routing_nodes n ON n.floor=p.floor AND n.area_id=p.area_id
                AND n.ref_table='door_access_points' AND n.ref_id=p.id
                AND (ST_OrderingEquals(n.geom,p.geom)
                    OR fn_route_line_valid_inside_area(p.area_id,ST_MakeLine(p.geom,n.geom),0.20))
            LEFT JOIN live_nodes l ON l.id=n.id
            LEFT JOIN door_access_points nearby ON nearby.id=i.near_id AND nearby.floor=p.floor
            ORDER BY distance_m NULLS LAST,p.id,n.id
            SQL, [json_encode(array_values($areaIds), JSON_THROW_ON_ERROR), $floor, $mode, $gender, $lang, $nearAccessId]);
    }

    public function resolve(array $point, int $floor, string $mode, string $gender, string $lang): array
    {
        $accessId = (int) $point['accessId'];
        $areaId = (int) $point['areaId'];
        $rows = collect($this->forAreas([$areaId], $floor, $mode, $gender, $lang, $accessId));
        $selected = $rows->firstWhere('access_id', $accessId);

        if ($selected?->status === 'available') {
            return ['lat' => (float) $selected->lat, 'lon' => (float) $selected->lon, 'node' => (int) $selected->node_id];
        }

        $replacement = $selected ? $rows->first(fn ($row) => $row->status === 'available' && (int) $row->access_id !== $accessId) : null;
        return [
            'reason' => $selected?->status ?? 'unavailable',
            'replacement' => $replacement ? [
                'accessId' => (int) $replacement->access_id,
                'areaId' => $areaId,
                'doorId' => (int) $replacement->door_id,
                'floor' => $floor,
                'name' => $replacement->name,
                'coordinates' => [(float) $replacement->lat, (float) $replacement->lon],
                'distanceMeters' => (float) $replacement->distance_m,
            ] : null,
        ];
    }
}
