-- Local GoldenPath only. Run after the manual-door-wall database change.
-- The transaction commits only if the display repair leaves routing identical.
\set ON_ERROR_STOP on
BEGIN ISOLATION LEVEL REPEATABLE READ;
SET LOCAL statement_timeout = '120s';
SET LOCAL client_min_messages = warning;

SELECT id FROM public.doors WHERE id = 2200088 FOR UPDATE;

CREATE TEMP VIEW door_repair_routing AS
SELECT
    (SELECT md5(string_agg(to_jsonb(p)::text, ',' ORDER BY p.id)) FROM public.door_access_points p) AS points,
    (SELECT md5(string_agg(to_jsonb(n)::text, ',' ORDER BY n.id)) FROM public.routing_nodes n) AS nodes,
    (SELECT md5(string_agg(to_jsonb(e)::text, ',' ORDER BY e.id)) FROM public.routing_edges_static e) AS edges,
    (SELECT md5(string_agg(to_jsonb(a)::text, ',' ORDER BY to_jsonb(a)::text)) FROM public.routing_edge_areas a) AS edge_areas;

CREATE TEMP VIEW door_repair_route AS
SELECT r.*
FROM public.door_access_points p
CROSS JOIN LATERAL public.fn_route_multifloor_edges(
    transaction_timestamp(), 'both'::public.gender_enum, 'walk', p.floor, p.floor,
    public.fn_dap_node_geom_inside_area(p.geom, p.from_area, 0.60),
    public.fn_dap_node_geom_inside_area(p.geom, p.to_area, 0.60), 1
) r
WHERE p.id = 2200091 AND p.door_id = 2200088;

CREATE TEMP TABLE before_routing AS SELECT * FROM door_repair_routing;
CREATE TEMP TABLE before_route AS SELECT * FROM door_repair_route;
CREATE TEMP TABLE before_door AS SELECT * FROM public.doors WHERE id = 2200088;

DO $repair$
DECLARE
    candidate geometry;
    access_point geometry;
    source_wall geometry;
    opposite_wall geometry;
BEGIN
    IF current_database() <> 'golden_path'
        OR (SELECT count(*) FROM public.door_access_points WHERE door_id = 2200088) <> 1
        OR NOT EXISTS (SELECT 1 FROM before_route WHERE door_id = 2200088) THEN
        RAISE EXCEPTION 'Expected local door and a route crossing it';
    END IF;

    SELECT p.geom, ST_Boundary(a.geom), ST_Boundary(b.geom)
    INTO STRICT access_point, source_wall, opposite_wall
    FROM public.door_access_points p
    JOIN public.areas a ON a.id = p.from_area AND a.floor = p.floor
    JOIN public.areas b ON b.id = p.to_area AND b.floor = p.floor
    JOIN before_door d ON d.id = p.door_id AND d.from_area = p.from_area
        AND d.to_area = p.to_area AND d.floor = p.floor
    WHERE p.id = 2200091 AND p.floor = 0 AND p.from_area = 1001001 AND p.to_area = 1001020;

    candidate := public.fn_door_display_on_wall(access_point, source_wall, opposite_wall);
    IF candidate IS NULL OR ST_NPoints(candidate) <> 2 OR ST_Length(candidate) NOT BETWEEN 1.1 AND 1.2
       OR NOT ST_DWithin(ST_LineInterpolatePoint(candidate, 0.5), access_point, 0.000001)
       OR EXISTS (
            SELECT 1 FROM ST_DumpPoints(ST_Segmentize(candidate, 0.005)) AS sample
            WHERE NOT ST_DWithin(sample.geom, source_wall, 0.0051)
               OR NOT ST_DWithin(sample.geom, opposite_wall, 0.0201)
       ) THEN
        RAISE EXCEPTION 'Repair must stay on the same wall and centered on the saved access point';
    END IF;

    UPDATE public.doors SET geom = candidate WHERE id = 2200088;
END;
$repair$;

CREATE TEMP TABLE after_route AS SELECT * FROM door_repair_route;

DO $verify$
BEGIN
    IF EXISTS (TABLE door_repair_routing EXCEPT TABLE before_routing)
       OR EXISTS (TABLE before_route EXCEPT ALL TABLE after_route)
       OR EXISTS (TABLE after_route EXCEPT ALL TABLE before_route)
       OR EXISTS (
           SELECT 1 FROM before_door b JOIN public.doors d USING (id)
           WHERE to_jsonb(b) - 'geom' IS DISTINCT FROM to_jsonb(d) - 'geom'
       ) THEN
        RAISE EXCEPTION 'Access points, routing graph, route output, and door metadata must stay unchanged';
    END IF;
END;
$verify$;

SELECT d.id, ST_NPoints(b.geom) AS before_vertices, ST_NPoints(d.geom) AS after_vertices,
       round(ST_Length(b.geom)::numeric, 3) AS before_width_m,
       round(ST_Length(d.geom)::numeric, 3) AS after_width_m,
       (SELECT count(*) FROM after_route) AS route_segments,
       (SELECT round(sum(distance_m)::numeric, 3) FROM after_route) AS route_m
FROM before_door b JOIN public.doors d USING (id);
COMMIT;
