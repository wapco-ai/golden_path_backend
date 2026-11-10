<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AreaEndpointService
{
    public const MIN_PICKER_AREA_M2 = 100;

    // A group is available only when all configured public members exist on its floor.
    private const PUBLIC_GROUPS_SQL = <<<'SQL'
        SELECT g.id, g.floor, g.area_ids, g.search_excluded_area_ids, ST_Union(a.geom) AS geom
        FROM input
        CROSS JOIN LATERAL jsonb_to_recordset(input.groups)
            AS g(id text, floor smallint, area_type text, area_ids bigint[], search_excluded_area_ids bigint[])
        JOIN areas a ON a.id = ANY(g.area_ids) AND a.floor = g.floor AND a.area_type::text = g.area_type
        WHERE (a.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
          AND NOT ST_IsEmpty(a.geom)
        GROUP BY g.id, g.floor, g.area_ids, g.search_excluded_area_ids
        HAVING count(*) = cardinality(g.area_ids)
        SQL;

    // The existing include_exact_areas option now includes partial matches too.
    public function includeExactMatches(array $places, string $search, string $language, int $limit): array
    {
        $publicGroups = self::PUBLIC_GROUPS_SQL;
        $groups = [];
        foreach (config('area_endpoints.groups', []) as $id => $group) {
            $groups[] = ['id' => $id, ...$group];
        }

        $rows = DB::select(<<<SQL
            WITH input AS (
                SELECT NULLIF(public.fn_place_search_key(?::text), '') AS search,
                    ?::lang_enum AS lang, ?::jsonb AS poi_ids, ?::jsonb AS groups, ?::jsonb AS aliases
            ), area_names AS (
                SELECT i.entity_id, i.txt
                FROM i18n_texts i CROSS JOIN input
                WHERE i.entity_table = 'areas' AND i.field = 'name' AND i.lang = input.lang
            ), groups AS (
                SELECT g.*, area_names.txt AS title
                FROM ($publicGroups) g
                LEFT JOIN area_names ON area_names.entity_id = g.area_ids[1]
            ), candidates AS (
                SELECT a.id, NULL::text AS group_id, a.floor, a.geom,
                    COALESCE(area_names.txt, input.aliases -> a.id::text ->> 0) AS title,
                    input.aliases -> a.id::text AS aliases
                FROM areas a CROSS JOIN input
                LEFT JOIN area_names ON area_names.entity_id = a.id
                WHERE (a.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
                  AND NOT EXISTS (SELECT 1 FROM groups g WHERE a.id = ANY(g.area_ids))
                UNION ALL
                SELECT NULL::bigint, g.id, g.floor, g.geom, g.title, NULL::jsonb FROM groups g
            ), pois AS (
                SELECT p.id, p.floor, p.geom, public.fn_place_search_key(i.txt) AS title_key
                FROM input CROSS JOIN LATERAL jsonb_array_elements_text(input.poi_ids) AS ids(id)
                JOIN poi_points p ON p.id = ids.id::bigint
                JOIN i18n_texts i ON i.entity_table = 'poi_points' AND i.entity_id = p.id
                    AND i.field = 'name' AND i.lang = input.lang
            ), area_keys AS (
                SELECT c.*, public.fn_place_search_key(c.title) AS title_key,
                    ARRAY(SELECT public.fn_place_search_key(alias.name)
                          FROM jsonb_array_elements_text(c.aliases) AS alias(name)) AS alias_keys
                FROM candidates c
            ), eligible_areas AS (
                SELECT a.*, a.title_key = input.search OR input.search = ANY(a.alias_keys) AS exact_match
                FROM area_keys a CROSS JOIN input
                WHERE (strpos(a.title_key, input.search) > 0
                   OR EXISTS (
                       SELECT 1 FROM unnest(a.alias_keys) AS alias(name)
                       WHERE strpos(alias.name, input.search) > 0
                   )) AND ST_Area(a.geom) >= ?
            )
            SELECT a.id, a.group_id, a.floor, a.title, a.exact_match, ST_Y(point.geom) AS lat, ST_X(point.geom) AS lon,
                to_jsonb(g.area_ids) AS member_area_ids,
                (SELECT jsonb_agg(p.id::text) FROM pois p
                 WHERE p.floor = a.floor AND (p.title_key = a.title_key OR p.title_key = ANY(a.alias_keys))
                   AND ST_Covers(a.geom, p.geom)) AS duplicate_poi_ids
            FROM eligible_areas a
            LEFT JOIN groups g ON g.id = a.group_id
            CROSS JOIN LATERAL (SELECT ST_Transform(ST_PointOnSurface(a.geom), 4326) AS geom) point
            WHERE NOT EXISTS (
                SELECT 1
                FROM eligible_areas matched_group
                JOIN groups configured_group ON configured_group.id = matched_group.group_id
                WHERE a.id = ANY(configured_group.search_excluded_area_ids)
                  AND a.title_key = matched_group.title_key
            )
            ORDER BY a.exact_match DESC NULLS LAST,
                starts_with(a.title_key, (SELECT search FROM input)) DESC NULLS LAST,
                a.floor, a.id, a.group_id
            LIMIT ?
            SQL, [
                $search, $language, json_encode(array_column($places, 'id'), JSON_THROW_ON_ERROR),
                json_encode($groups, JSON_THROW_ON_ERROR),
                json_encode(config("area_endpoints.search_aliases.{$language}", []), JSON_THROW_ON_ERROR),
                self::MIN_PICKER_AREA_M2, $limit,
            ]);

        $exactAreas = [];
        $partialAreas = [];
        $pois = array_column($places, null, 'id');
        $exactCount = count(array_filter($rows, fn ($row) => $row->exact_match));
        $areaSlots = $exactCount + intdiv($limit - $exactCount + 1, 2);
        foreach ($rows as $index => $row) {
            // Reserve roughly half the open slots for partial areas. Add more only
            // when there are too few distinct points to fill the response.
            if ($index >= $areaSlots && $index + count($pois) >= $limit) {
                break;
            }

            $identity = $row->group_id !== null
                ? [
                    'id' => "area-group-{$row->group_id}",
                    'areaGroupId' => $row->group_id,
                    'memberAreaIds' => json_decode($row->member_area_ids, true, flags: JSON_THROW_ON_ERROR),
                ]
                : ['id' => "area-{$row->id}", 'areaId' => (int) $row->id];
            $area = [
                ...$identity,
                'kind' => 'area',
                'title' => $row->title,
                'floor' => (int) $row->floor,
                'coordinates' => [(float) $row->lat, (float) $row->lon],
            ];
            if ($row->exact_match) {
                $exactAreas[] = $area;
            } else {
                $partialAreas[] = $area;
            }
            foreach (json_decode($row->duplicate_poi_ids ?? '[]', true, flags: JSON_THROW_ON_ERROR) as $poiId) {
                if (isset($pois[$poiId]['content'])) {
                    // The area is the routing result, but its POI still has information to show.
                    $pois[$poiId]['kind'] = 'cultural';
                } else {
                    unset($pois[$poiId]);
                }
            }
        }

        $pois = array_values($pois);
        // Interleave partial areas with points so a broad match can surface both.
        $results = $exactAreas;
        foreach ($partialAreas as $index => $area) {
            $results[] = $area;
            if (isset($pois[$index])) {
                $results[] = $pois[$index];
            }
        }

        return array_slice([...$results, ...array_slice($pois, count($partialAreas))], 0, $limit);
    }

    public function doors(?int $areaId, string $language, ?int $floor, ?string $areaGroupId = null): ?string
    {
        $registry = config('area_endpoints.groups', []);
        $groups = [];
        if ($areaGroupId !== null) {
            $group = $registry[$areaGroupId] ?? null;
            if ($group === null) {
                return null;
            }
            $groups[] = ['id' => $areaGroupId, ...$group];
        } else {
            // A recent selection may still refer to one member from before grouping.
            foreach ($registry as $id => $group) {
                if (in_array($areaId, $group['area_ids'], true)) {
                    $groups[] = ['id' => $id, ...$group];
                    break;
                }
            }
        }

        $publicGroups = self::PUBLIC_GROUPS_SQL;
        return DB::selectOne(<<<SQL
            WITH input AS (
                SELECT ?::bigint AS area_id, ?::lang_enum AS lang, ?::smallint AS floor, ?::jsonb AS groups
            ), selected_group AS (
                SELECT g.*, fn_i18n_label('areas', g.area_ids[1], 'name', input.lang, 'fa') AS name
                FROM ($publicGroups) g CROSS JOIN input
                WHERE input.floor IS NULL OR g.floor = input.floor
            ), area_geometry AS (
                SELECT a.id, NULL::text AS group_id, ARRAY[a.id] AS area_ids, a.floor, a.geom,
                    fn_i18n_label('areas', a.id, 'name', input.lang, 'fa') AS name
                FROM areas a JOIN input ON input.area_id = a.id
                WHERE (a.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
                  AND (input.floor IS NULL OR a.floor = input.floor)
                  AND NOT EXISTS (SELECT 1 FROM selected_group)
                UNION ALL
                SELECT NULL::bigint, g.id, g.area_ids, g.floor, g.geom, g.name
                FROM selected_group g
            ), area AS (
                SELECT area_geometry.*, ST_Centroid(geom) AS center FROM area_geometry
            ), door_candidates AS (
                SELECT dap.id, dap.door_id, dap.geom, dap.from_area, dap.to_area, dap.needs_review,
                    a.group_id, a.floor,
                    CASE WHEN dap.from_area = ANY(a.area_ids) THEN dap.to_area ELSE dap.from_area END AS other_area_id,
                    COALESCE(dap.from_area = ANY(a.area_ids), false) AS from_member,
                    COALESCE(dap.to_area = ANY(a.area_ids), false) AS to_member,
                    ST_Azimuth(a.center, dap.geom) AS azimuth
                FROM area a JOIN door_access_points dap
                    ON dap.floor = a.floor AND (dap.from_area = ANY(a.area_ids) OR dap.to_area = ANY(a.area_ids))
            ), doors AS (
                SELECT d.*
                FROM door_candidates d
                LEFT JOIN areas neighbor ON neighbor.id = d.other_area_id AND neighbor.floor = d.floor
                WHERE d.group_id IS NULL OR (
                    d.from_member <> d.to_member
                    AND NOT COALESCE(d.needs_review, false)
                    AND neighbor.id IS NOT NULL
                    AND (neighbor.attrs #>> '{access_policy,public_access}') IS DISTINCT FROM 'false'
                )
            ), named_doors AS (
                SELECT doors.*,
                    row_number() OVER (
                        ORDER BY CASE WHEN azimuth < 7 * pi() / 4 THEN azimuth + 2 * pi() ELSE azimuth END, id
                    ) AS door_no,
                    fn_i18n_label('areas', from_area, 'name', input.lang, 'fa') AS from_area_name,
                    fn_i18n_label('areas', to_area, 'name', input.lang, 'fa') AS to_area_name,
                    fn_i18n_label('areas', other_area_id, 'name', input.lang, 'fa') AS other_area_name,
                    ST_Transform(geom, 4326) AS wgs84
                FROM doors CROSS JOIN input
            )
            SELECT jsonb_build_object(
                'area', jsonb_build_object(
                    'id', COALESCE(to_jsonb(a.id), to_jsonb('area-group-' || a.group_id)),
                    'name', a.name, 'floor', a.floor, 'areaM2', ST_Area(a.geom),
                    'coord32640', jsonb_build_array(ST_X(a.center), ST_Y(a.center)),
                    'coord4326', jsonb_build_array(ST_X(map.center), ST_Y(map.center)),
                    'bbox4326', jsonb_build_array(ST_XMin(map.geom), ST_YMin(map.geom), ST_XMax(map.geom), ST_YMax(map.geom)),
                    'geometry', ST_AsGeoJSON(map.geom)::jsonb
                ) || CASE WHEN a.group_id IS NULL THEN '{}'::jsonb
                    ELSE jsonb_build_object('areaGroupId', a.group_id, 'memberAreaIds', a.area_ids) END,
                'doors', COALESCE((
                    SELECT jsonb_agg(jsonb_build_object(
                        'doorNo', door_no, 'doorId', door_id, 'accessId', id,
                        'label', format('درب شماره %s از %s به %s', door_no, a.name, COALESCE(other_area_name, 'نامشخص')),
                        'fromAreaId', from_area, 'fromAreaName', from_area_name,
                        'toAreaId', to_area, 'toAreaName', to_area_name,
                        'otherAreaId', other_area_id, 'otherAreaName', other_area_name,
                        'coord32640', jsonb_build_array(ST_X(geom), ST_Y(geom)),
                        'coord4326', jsonb_build_array(ST_X(wgs84), ST_Y(wgs84))
                    ) ORDER BY door_no) FROM named_doors
                ), '[]'::jsonb)
            ) AS payload
            FROM area a
            CROSS JOIN LATERAL (SELECT ST_Transform(a.geom, 4326) AS geom, ST_Transform(a.center, 4326) AS center) map
            SQL, [$areaId, $language, $floor, json_encode($groups, JSON_THROW_ON_ERROR)])?->payload;
    }
}
