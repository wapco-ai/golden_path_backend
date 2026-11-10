-- Run after the nearby-route and same-floor stair migrations in a test database.
BEGIN;

INSERT INTO public.routing_floors(floor, label, sort_order) VALUES (0, 'Ground', 0)
ON CONFLICT (floor) DO NOTHING;

INSERT INTO public.areas(id, geom, area_type, floor, allowed_gender, is_closed, attrs) VALUES
    (991400001, ST_Multi(ST_MakeEnvelope(700000,4000000,700010,4000010,32640)),
        'riwaq', 0, 'both', false, '{"routing_role":"routable"}'),
    (991400002, ST_Multi(ST_MakeEnvelope(700010,4000000,700020,4000010,32640)),
        'riwaq', 0, 'both', false, '{"routing_role":"routable"}');

INSERT INTO public.doors(id, geom, from_area, to_area, floor, allowed_gender, is_open, modes, bidirectional)
VALUES (991400001, ST_GeomFromText('LINESTRING(700010 4000004,700010 4000006)',32640),
    991400001, 991400002, 0, 'both', true, ARRAY['walk'], true);

INSERT INTO public.door_access_points(id, door_id, geom, floor, from_area, to_area, confidence, build_method, needs_review)
VALUES (991400001, 991400001, ST_GeomFromText('POINT(700010 4000005)',32640),
    0, 991400001, 991400002, 1, 'nearby_zero_length_test', false);

INSERT INTO public.routing_nodes(id, floor, geom, kind, ref_table, ref_id, area_id)
SELECT a.id, d.floor, d.geom, 'door_access', 'door_access_points', d.id, a.id
FROM public.door_access_points d
JOIN public.areas a ON a.id IN (d.from_area,d.to_area)
WHERE d.id=991400001;

INSERT INTO public.routing_edges_static(id, floor, src, dst, geom, base_cost, door_id)
SELECT 991400001, s.floor, s.id, t.id, ST_MakeLine(s.geom,t.geom), 0.01, 991400001
FROM public.routing_nodes s CROSS JOIN public.routing_nodes t
WHERE s.id=991400001 AND t.id=991400002;

REFRESH MATERIALIZED VIEW public.mv_area_door_stats;

DO $check$
DECLARE
    outside_point geometry := ST_GeomFromText('POINT(700010 4000012)',32640);
    inside_point geometry := ST_GeomFromText('POINT(700016 4000005)',32640);
    portal_point geometry := ST_GeomFromText('POINT(700010 4000005)',32640);
    clipped record;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.fn_route_nearby_origin_edges(now(),'both','walk',0::smallint,0::smallint,
            outside_point,inside_point)
        WHERE edge_id=991400001 AND from_node < -10
    ) THEN
        RAISE EXCEPTION 'Fixture did not route from a snapped origin on the shared DAP edge';
    END IF;

    -- Inspect every synthetic leg because tie-breaking may pick an equal-fraction leg.
    IF EXISTS (
        SELECT 1 FROM pg_temp.gp_origin_edges
        WHERE ref_edge=991400001 AND (source < -10 OR target < -10)
          AND (ST_GeometryType(geom) IS DISTINCT FROM 'ST_LineString'
               OR ST_Length(geom) IS DISTINCT FROM 0::double precision
               OR ST_NPoints(geom) IS DISTINCT FROM 2)
    ) THEN
        RAISE EXCEPTION 'Clipping the zero-length origin edge broke the LineString contract';
    END IF;

    SELECT * INTO STRICT clipped
    FROM public.fn_route_nearby_edges(now(),'both','walk',0::smallint,0::smallint,
        portal_point,inside_point)
    WHERE edge_id=991400001 AND to_node=-2;

    IF clipped.distance_m IS DISTINCT FROM 0::double precision
        OR (clipped.geometry_json::jsonb->>'type') IS DISTINCT FROM 'LineString'
        OR jsonb_array_length(clipped.geometry_json::jsonb->'coordinates') IS DISTINCT FROM 2
        OR (clipped.geometry_json::jsonb#>'{coordinates,0}') IS DISTINCT FROM
           (clipped.geometry_json::jsonb#>'{coordinates,1}') THEN
        RAISE EXCEPTION 'Clipping the zero-length destination edge broke the LineString contract';
    END IF;
END;
$check$;

ROLLBACK;
