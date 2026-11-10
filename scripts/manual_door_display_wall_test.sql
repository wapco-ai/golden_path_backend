-- Run after the manual door display wall change. All fixture rows roll back.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL statement_timeout = '30s';

DO $test$
DECLARE
    left_area bigint;
    right_area bigint;
    placed record;
    moved record;
    expected geometry;
    old_dap bigint;
BEGIN
    INSERT INTO public.areas(geom, area_type, floor, attrs)
    VALUES (
        ST_Multi(ST_GeomFromText('POLYGON((750000 4100000,750010 4100000,750010 4100005,750010 4100010,750000 4100010,750000 4100000))', 32640)),
        'courtyard', 0, '{"routing_role":"routable"}'
    ) RETURNING id INTO left_area;

    INSERT INTO public.areas(geom, area_type, floor, attrs)
    VALUES (ST_Multi(ST_MakeEnvelope(750010, 4100000, 750020, 4100008, 32640)),
        'courtyard', 0, '{"routing_role":"routable"}') RETURNING id INTO right_area;

    expected := ST_SetSRID(ST_MakePoint(750010, 4100005), 32640);
    SELECT * INTO STRICT placed FROM public.fn_create_door_from_click(750009.98, 4100005, 0::smallint);
    IF placed.door_from_area <> left_area OR placed.door_to_area IS DISTINCT FROM right_area
       OR ST_NPoints(placed.door_geom) <> 2 OR abs(ST_Length(placed.door_geom) - 2) > 0.000001
       OR NOT ST_Equals(placed.dap_geom, expected)
       OR NOT ST_DWithin(ST_LineInterpolatePoint(placed.door_geom, 0.5), expected, 0.000001) THEN
        RAISE EXCEPTION 'Creation failed to combine collinear vertices on the shared wall';
    END IF;

    BEGIN
        PERFORM public.fn_move_door_to_click(placed.door_id, 750010, 4100000, 0::smallint);
        RAISE EXCEPTION 'Move on a corner was accepted';
    EXCEPTION WHEN SQLSTATE 'GP001' THEN
        NULL;
    END;
    IF NOT EXISTS (
        SELECT 1 FROM public.door_access_points p WHERE p.id = placed.dap_id AND ST_Equals(p.geom, expected)
    ) THEN
        RAISE EXCEPTION 'Rejected move altered the existing access point';
    END IF;

    expected := ST_SetSRID(ST_MakePoint(750010, 4100000.55), 32640);
    SELECT * INTO STRICT moved FROM public.fn_move_door_to_click(placed.door_id, 750009.98, 4100000.55, 0::smallint);
    IF moved.door_id <> placed.door_id OR moved.door_to_area IS DISTINCT FROM right_area
       OR ST_NPoints(moved.door_geom) <> 2 OR abs(ST_Length(moved.door_geom) - 1.10) > 0.015
       OR NOT ST_Equals(moved.dap_geom, expected)
       OR NOT ST_DWithin(ST_LineInterpolatePoint(moved.door_geom, 0.5), expected, 0.000001)
       OR ST_X(ST_StartPoint(moved.door_geom)) <> 750010 OR ST_X(ST_EndPoint(moved.door_geom)) <> 750010 THEN
        RAISE EXCEPTION 'Move near a corner did not stay centered on the same wall';
    END IF;

    SELECT * INTO STRICT moved FROM public.fn_move_door_to_click(placed.door_id, 750009.98, 4100007.6, 0::smallint);
    IF moved.door_to_area IS DISTINCT FROM right_area OR ST_Length(moved.door_geom) NOT BETWEEN 0.78 AND 0.84 THEN
        RAISE EXCEPTION 'Display line continued past the end of the shared wall';
    END IF;

    BEGIN
        PERFORM public.fn_create_door_from_click(750010, 4100000, 0::smallint);
        RAISE EXCEPTION 'Creation on a corner was accepted';
    EXCEPTION WHEN SQLSTATE 'GP001' THEN
        NULL;
    END;

    INSERT INTO public.areas(geom, area_type, floor, attrs)
    VALUES (ST_Multi(ST_MakeEnvelope(750020.08, 4100000, 750030, 4100008, 32640)),
        'courtyard', 0, '{"routing_role":"routable"}');
    BEGIN
        PERFORM public.fn_create_door_from_click(750020.04, 4100004, 0::smallint);
        RAISE EXCEPTION 'Click equidistant between parallel walls was accepted';
    EXCEPTION WHEN SQLSTATE 'GP001' THEN
        NULL;
    END;

    INSERT INTO public.areas(geom, area_type, floor, attrs)
    VALUES (ST_Multi(ST_MakeEnvelope(750000, 4100000, 750010, 4100010, 32640)),
        'courtyard', -1, '{"routing_role":"routable"}');
    old_dap := moved.dap_id;
    SELECT * INTO STRICT moved FROM public.fn_move_door_to_click(placed.door_id, 750010, 4100005, -1::smallint);
    IF moved.door_to_area IS NOT NULL OR EXISTS (SELECT 1 FROM public.door_access_points p WHERE p.id = old_dap)
       OR NOT EXISTS (SELECT 1 FROM public.door_access_points p WHERE p.id = moved.dap_id AND p.floor = -1 AND p.needs_review) THEN
        RAISE EXCEPTION 'Moving to another floor did not replace the old access point with the one-sided point';
    END IF;

    RAISE NOTICE 'PASS: collinear vertices, shared wall, centered short line, corner and parallel-wall rejection, and cross-floor move';
END;
$test$;
ROLLBACK;
