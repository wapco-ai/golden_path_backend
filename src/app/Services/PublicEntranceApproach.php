<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PublicEntranceApproach
{
    /** Routes from a pin in the public exterior to a verified boundary DAP. */
    public function find(array $origin, int $originFloor, int $floor, array $areas, ?int $access, string $mode, string $gender): array
    {
        if ($originFloor !== $floor || isset($origin['node']) || !$areas || !in_array($mode, ['walk', 'wheelchair'], true)) {
            return [];
        }

        return DB::select(<<<'SQL'
            WITH input AS MATERIALIZED (
                SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?,?),4326),32640) AS pin,
                       ?::smallint AS floor, ?::gender_enum AS gender, ?::text AS mode,
                       ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(?::jsonb)) AS requested,
                       ?::bigint AS access
            ), located AS MATERIALIZED (
                SELECT i.*, fn_route_point_area_id(now(),i.gender,i.mode,i.floor,i.pin) AS id FROM input i
            ), permitted AS MATERIALIZED (
                SELECT a.id FROM input i CROSS JOIN LATERAL fn_allowed_areas(now(),i.gender,i.mode,i.floor) a WHERE a.is_allowed
            ), exterior AS MATERIALIZED (
                SELECT i.*, a.geom FROM located i JOIN areas a ON a.id=i.id JOIN permitted perm ON perm.id=a.id
                WHERE a.floor=i.floor AND ST_Covers(a.geom,i.pin)
                  AND a.attrs #>> '{access_policy,public_access}' IS DISTINCT FROM 'false'
            ), candidates AS MATERIALIZED (
                SELECT x.*, p.id AS access_id, p.geom AS dap
                FROM exterior x JOIN door_access_points p ON p.floor=x.floor AND x.id IN (p.from_area,p.to_area)
                JOIN areas inside ON inside.id=CASE WHEN p.from_area=x.id THEN p.to_area ELSE p.from_area END
                JOIN routing_nodes n ON n.floor=p.floor AND n.area_id=x.id AND n.ref_table='door_access_points'
                    AND n.ref_id=p.id AND ST_Equals(n.geom,p.geom)
                JOIN LATERAL fn_allowed_doors(now(),x.gender,x.mode,x.floor) d ON d.id=p.door_id AND d.is_open AND d.is_allowed
                WHERE NOT p.needs_review AND inside.floor=x.floor AND inside.attrs #>> '{access_policy,public_access}'='false'
                  AND (inside.id=ANY(x.requested) OR x.id=ANY(x.requested)) AND (x.access IS NULL OR p.id=x.access)
                  AND ST_Covers(ST_Boundary(x.geom),p.geom) AND ST_Covers(ST_Boundary(inside.geom),p.geom)
            ), paths AS MATERIALIZED (
                SELECT c.*, fn_build_intra_area_edge_geom(c.id,c.floor,c.pin,c.dap,0.10) AS line FROM candidates c
            )
            SELECT access_id, ST_X(ST_Transform(dap,4326)) AS lon, ST_Y(ST_Transform(dap,4326)) AS lat,
                ST_AsGeoJSON(ST_Transform(line,4326)) AS geometry_json,
                ST_AsGeoJSON(ST_Transform(pin,4326)) AS from_point, ST_AsGeoJSON(ST_Transform(dap,4326)) AS to_point,
                floor AS from_floor, floor AS to_floor, -1 AS from_node, -2 AS to_node,
                NULL::bigint AS edge_id, NULL::bigint AS door_id, NULL::bigint AS connector_id, NULL::bigint AS stair_id,
                ST_Length(line) AS distance_m,
                ST_Length(line) / CASE WHEN mode='wheelchair' THEN 1.0 ELSE fn_routing_walk_speed() END AS duration_s,
                'connection' AS terminal_kind
            FROM paths r WHERE line IS NOT NULL AND NOT ST_IsEmpty(line) AND ST_Covers(r.geom,line)
                AND ST_Equals(ST_StartPoint(line),pin) AND ST_Equals(ST_EndPoint(line),dap)
                AND fn_route_line_valid_inside_area(r.id,line,0.10)
                AND NOT EXISTS (SELECT FROM areas z WHERE z.floor=r.floor AND z.geom && line
                    AND (z.attrs #>> '{access_policy,public_access}'='false' OR NOT EXISTS (
                        SELECT FROM permitted ok WHERE ok.id=z.id))
                    AND (ST_Relate(line,z.geom,'T********') OR ST_Contains(z.geom,pin) OR ST_Contains(z.geom,dap)))
                AND NOT EXISTS (SELECT FROM v_temp_block_areas_active b WHERE b.floor=r.floor AND ST_Intersects(b.geom,line))
                AND NOT EXISTS (SELECT FROM fn_admin_restrictions_active(now(),r.gender,r.mode,r.floor) b
                    WHERE b.geom IS NOT NULL AND ST_Intersects(b.geom,line))
            ORDER BY distance_m,access_id
            SQL, [$origin['lon'], $origin['lat'], $floor, $gender, $mode, json_encode(array_values($areas), JSON_THROW_ON_ERROR), $access]);
    }
}
