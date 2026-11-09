-- Run against a disposable database with the same-floor stairs migration applied.
BEGIN;

INSERT INTO public.routing_floors(floor, label, sort_order) VALUES (0, 'Ground', 0)
ON CONFLICT (floor) DO NOTHING;

INSERT INTO public.areas(id, geom, area_type, floor, allowed_gender, is_closed, attrs) VALUES
    (991300001, ST_Multi(ST_MakeEnvelope(700000,4000000,700010,4000010,32640)), 'riwaq', 0, 'both', false, '{"routing_role":"routable"}'),
    (991300002, ST_Multi(ST_MakeEnvelope(700015,4000000,700025,4000010,32640)), 'riwaq', 0, 'both', false, '{"routing_role":"routable"}'),
    (991300003, ST_Multi(ST_MakeEnvelope(700010,4000000,700015,4000005,32640)), 'stair_area', 0, 'female', false, '{"routing_role":"transparent","operational":{"transport_modes":["walk"]}}');

INSERT INTO public.doors(id, geom, from_area, to_area, floor, allowed_gender, is_open, modes, bidirectional) VALUES
    (991300001, ST_GeomFromText('LINESTRING(700010 4000001,700010 4000003)',32640), 991300001, NULL, 0, 'both', true, ARRAY['walk','wheelchair'], true),
    (991300002, ST_GeomFromText('LINESTRING(700015 4000001,700015 4000003)',32640), 991300002, NULL, 0, 'both', true, ARRAY['walk','wheelchair'], true);

INSERT INTO public.door_access_points(id, door_id, geom, floor, from_area, to_area, confidence, build_method, needs_review) VALUES
    (991300001, 991300001, ST_GeomFromText('POINT(700010 4000002)',32640), 0, 991300001, NULL, 1, 'same_floor_stair_test', false),
    (991300002, 991300002, ST_GeomFromText('POINT(700015 4000002)',32640), 0, 991300002, NULL, 1, 'same_floor_stair_test', false);

INSERT INTO public.routing_nodes(id, floor, geom, kind, ref_table, ref_id, area_id)
SELECT dap.id, dap.floor, dap.geom, 'door_access', 'door_access_points', dap.id, dap.from_area
FROM public.door_access_points dap WHERE dap.id IN (991300001, 991300002);

REFRESH MATERIALIZED VIEW public.mv_area_door_stats;

INSERT INTO public.same_floor_stairs
    (id, stair_area_id, floor, start_access_id, end_access_id, start_area_id, end_area_id, direction, travel_seconds, reverse_seconds)
VALUES (991300001, 991300003, 0, 991300001, 991300002, 991300001, 991300002, 'forward', 12, 19);

-- The door drawing moves, while the access points and their derived edge stay fixed.
UPDATE public.doors SET geom = ST_Translate(geom, 100, 100) WHERE id IN (991300001, 991300002);

DO $check$
DECLARE
    stair_edge public.routing_edges_static%ROWTYPE;
    live_record record;
BEGIN
    SELECT * INTO STRICT stair_edge FROM public.routing_edges_static
    WHERE attrs->>'edge_type' = 'same_floor_stair' AND attrs->>'stair_id' = '991300001';
    IF stair_edge.src <> 991300001 OR stair_edge.dst <> 991300002 OR stair_edge.door_id IS NOT NULL
        OR NOT ST_Equals(stair_edge.geom, ST_GeomFromText('LINESTRING(700010 4000002,700015 4000002)',32640)) THEN
        RAISE EXCEPTION 'Stair geometry does not match the two ordered DAP-backed nodes';
    END IF;

    SELECT * INTO STRICT live_record FROM public.fn_routing_edges_live_param(now(), 'female', 'walk', 0::smallint)
    WHERE id = stair_edge.id;
    IF live_record.cost <= 0 OR live_record.reverse_cost >= 0 THEN
        RAISE EXCEPTION 'Ascending-only stair direction was not respected';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM public.fn_route_multifloor_edges(now(), 'female', 'walk', 0::smallint, 0::smallint,
            ST_GeomFromText('POINT(700006 4000002)',32640),
            ST_GeomFromText('POINT(700019 4000002)',32640), 1) r
        WHERE r.edge_id = stair_edge.id AND r.duration_s = 12
          AND r.from_floor = r.to_floor AND r.geometry_json IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'The full route did not use the same-floor edge and ascent time';
    END IF;
    IF EXISTS (SELECT 1 FROM public.fn_routing_edges_live_param(now(), 'female', 'wheelchair', 0::smallint) WHERE id = stair_edge.id)
        OR EXISTS (SELECT 1 FROM public.fn_routing_edges_live_param(now(), 'male', 'walk', 0::smallint) WHERE id = stair_edge.id)
        OR EXISTS (SELECT 1 FROM public.routing_edges_live WHERE id = stair_edge.id) THEN
        RAISE EXCEPTION 'An ineligible route can use the stair';
    END IF;
END;
$check$;

UPDATE public.routing_edges_static SET geom = ST_Translate(geom, 1, 1)
WHERE attrs->>'stair_id' = '991300001';
DO $check$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.fn_routing_edges_live_param(now(), 'female', 'walk', 0::smallint) e
        JOIN public.routing_edges_static es ON es.id = e.id WHERE es.attrs->>'stair_id' = '991300001'
    ) THEN
        RAISE EXCEPTION 'A stale edge that no longer matches the landing geometry is routable';
    END IF;
    PERFORM public.fn_refresh_same_floor_stair(991300001);
END;
$check$;

UPDATE public.same_floor_stairs SET direction = 'reverse' WHERE id = 991300001;
DO $check$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.fn_routing_edges_live_param(now(), 'female', 'walk', 0::smallint) e
        JOIN public.routing_edges_static es ON es.id = e.id
        WHERE es.attrs->>'stair_id' = '991300001' AND e.cost < 0 AND e.reverse_cost > 0
    ) THEN
        RAISE EXCEPTION 'Descending-only stair direction was not respected';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM public.fn_route_multifloor_edges(now(), 'female', 'walk', 0::smallint, 0::smallint,
            ST_GeomFromText('POINT(700019 4000002)',32640),
            ST_GeomFromText('POINT(700006 4000002)',32640), 1) r
        JOIN public.routing_edges_static es ON es.id = r.edge_id
        WHERE es.attrs->>'stair_id' = '991300001' AND r.duration_s = 19 AND r.from_floor = r.to_floor
    ) THEN
        RAISE EXCEPTION 'The reverse route did not use the same-floor edge and descent time';
    END IF;
END;
$check$;

INSERT INTO public.admin_restrictions(target_table, target_id, floor, restrict_type, penalty_w, modes, is_active)
VALUES ('areas', 991300003, 0, 'close', 1, ARRAY['walk'], true);
DO $check$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.fn_routing_edges_live_param(now(), 'female', 'walk', 0::smallint) e
        JOIN public.routing_edges_static es ON es.id = e.id WHERE es.attrs->>'stair_id' = '991300001'
    ) THEN
        RAISE EXCEPTION 'Closed stairs remain in the live graph';
    END IF;
END;
$check$;
DELETE FROM public.admin_restrictions WHERE target_table = 'areas' AND target_id = 991300003;

-- A GIS edit must not create a flat bypass through an existing stair footprint.
DO $check$
BEGIN
    INSERT INTO public.areas(id, geom, area_type, floor, allowed_gender, is_closed, attrs)
    VALUES (991300004, ST_Multi(ST_MakeEnvelope(700012,4000001,700013,4000003,32640)), 'riwaq', 0, 'both', false, '{"routing_role":"routable"}');
    RAISE EXCEPTION 'A routable area was allowed to create a flat bypass over the stair';
EXCEPTION WHEN check_violation THEN
    NULL;
END;
$check$;
UPDATE public.same_floor_stairs SET is_active = false WHERE id = 991300001;
INSERT INTO public.areas(id, geom, area_type, floor, allowed_gender, is_closed, attrs)
VALUES (991300004, ST_Multi(ST_MakeEnvelope(700012,4000001,700013,4000003,32640)), 'riwaq', 0, 'both', false, '{"routing_role":"routable"}');
DELETE FROM public.areas WHERE id = 991300004;
UPDATE public.same_floor_stairs SET is_active = true WHERE id = 991300001;

-- Rebuild one landing node, then rebuild only the area's incident edges.
DELETE FROM public.routing_edges_static WHERE attrs->>'stair_id' = '991300001';
DELETE FROM public.routing_nodes WHERE id = 991300001;
INSERT INTO public.routing_nodes(id, floor, geom, kind, ref_table, ref_id, area_id)
SELECT 991300011, dap.floor, dap.geom, 'door_access', 'door_access_points', dap.id, dap.from_area
FROM public.door_access_points dap WHERE dap.id = 991300001;
DELETE FROM public.routing_edges_static WHERE attrs->>'stair_id' = '991300001';
SET CONSTRAINTS same_floor_stair_edge_removed IMMEDIATE;
DO $check$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.routing_edges_static WHERE attrs->>'stair_id' = '991300001' AND src = 991300011) THEN
        RAISE EXCEPTION 'Stair was lost when its landing node or area edges were rebuilt';
    END IF;
END;
$check$;
SET CONSTRAINTS same_floor_stair_edge_removed DEFERRED;

UPDATE public.door_access_points SET needs_review = true WHERE id = 991300001;
DO $check$
BEGIN
    IF EXISTS (SELECT 1 FROM public.routing_edges_static WHERE attrs->>'stair_id' = '991300001') THEN
        RAISE EXCEPTION 'A stair with an unreviewed landing is still present';
    END IF;
END;
$check$;
UPDATE public.door_access_points SET needs_review = false WHERE id = 991300001;
DELETE FROM public.door_access_points WHERE id = 991300001;
DO $check$
BEGIN
    IF EXISTS (SELECT 1 FROM public.routing_edges_static WHERE attrs->>'stair_id' = '991300001')
        OR NOT EXISTS (
            SELECT 1 FROM public.same_floor_stairs
            WHERE id = 991300001 AND start_access_id IS NULL AND NOT is_active AND version = 2
        ) THEN
        RAISE EXCEPTION 'Deleted landing did not suspend the stair and preserve its definition';
    END IF;
END;
$check$;

ROLLBACK;
