-- Exercise a normal door, two one-sided spellings, and other review causes.
BEGIN;

INSERT INTO public.routing_floors(floor, label, sort_order) VALUES (0, 'Ground', 0)
ON CONFLICT (floor) DO NOTHING;

INSERT INTO public.areas(id, geom, area_type, floor, allowed_gender, is_closed, attrs) VALUES
    (991610001, ST_Multi(ST_MakeEnvelope(700000,4000000,700020,4000020,32640)),
        'riwaq', 0, 'both', false, '{"routing_role":"routable"}'),
    (991610002, ST_Multi(ST_MakeEnvelope(700020,4000000,700040,4000020,32640)),
        'riwaq', 0, 'both', false, '{"routing_role":"routable"}');

INSERT INTO public.doors(id, geom, from_area, to_area, floor, allowed_gender, is_open, modes, bidirectional)
SELECT id, ST_MakeLine(ST_SetSRID(ST_MakePoint(x,y-1),32640),ST_SetSRID(ST_MakePoint(x,y+1),32640)),
    from_area, to_area, 0, 'both', true, ARRAY['walk'], true
FROM (VALUES
    (991610011,700020,4000005,991610001,991610002),
    (991610012,700000,4000005,991610001,NULL),
    (991610013,700000,4000010,NULL,991610001),
    (991610014,700000,4000015,991610001,NULL),
    (991610015,699998,4000015,991610001,NULL),
    (991610016,700000,4000018,991610001,NULL)
) d(id,x,y,from_area,to_area);

INSERT INTO public.door_access_points(id, door_id, geom, floor, from_area, to_area, needs_review, review_reason)
SELECT d.id,d.id,ST_LineInterpolatePoint(d.geom,0.5),d.floor,d.from_area,d.to_area,
    d.id<>991610011, CASE d.id WHEN 991610011 THEN NULL WHEN 991610014 THEN 'ambiguous_candidate'
                         WHEN 991610016 THEN NULL ELSE 'one_sided_door' END
FROM public.doors d WHERE d.id BETWEEN 991610011 AND 991610016;

REFRESH MATERIALIZED VIEW public.mv_area_door_stats;

DO $check$
DECLARE
    result jsonb;
    local_node bigint;
    normal_node bigint;
    valid_access_count integer;
    local_point geometry;
    normal_point geometry;
BEGIN
    result := public.fn_rebuild_graph_for_door(991610011,false,2.50,0.10);
    IF (result->>'inserted_forward_door_edges')::integer <> 1 OR (result->>'inserted_reverse_door_edges')::integer <> 1 THEN
        RAISE EXCEPTION 'Normal door transitions failed: %',result;
    END IF;

    DROP TABLE pg_temp.tmp_door_affected_areas;

    result := public.fn_rebuild_graph_for_door(991610012,false,2.50,0.10);
    IF result->>'ok' IS DISTINCT FROM 'true' OR result->>'single_sided_connected' IS DISTINCT FROM 'true'
        OR (result->>'inserted_forward_door_edges')::integer <> 0 OR (result->>'inserted_reverse_door_edges')::integer <> 0 THEN
        RAISE EXCEPTION 'One-sided graph did not report a local connection: %',result;
    END IF;

    PERFORM public.fn_rebuild_graph_for_area(991610001,0::smallint,false,2.50,0.10);
    PERFORM public.fn_attach_one_sided_area_access(991610001,0::smallint,0.10);

    SELECT count(*) INTO valid_access_count
    FROM public.routing_nodes n JOIN public.door_access_points p ON p.id=n.ref_id
    WHERE n.ref_table='door_access_points' AND n.ref_id BETWEEN 991610012 AND 991610016
      AND n.area_id=991610001 AND ST_OrderingEquals(n.geom,p.geom);
    IF valid_access_count <> 3 OR EXISTS (
        SELECT 1 FROM public.routing_nodes WHERE ref_table='door_access_points' AND ref_id IN (991610014,991610015)
    ) THEN RAISE EXCEPTION 'Area rebuild incorrectly included or excluded a one-sided point'; END IF;

    SELECT id,geom INTO STRICT local_node,local_point FROM public.routing_nodes
    WHERE ref_table='door_access_points' AND ref_id=991610012;
    SELECT id,geom INTO STRICT normal_node,normal_point FROM public.routing_nodes
    WHERE ref_table='door_access_points' AND ref_id=991610011 AND area_id=991610001;

    IF (SELECT count(*) FROM public.routing_edges_static WHERE local_node IN (src,dst)) <> 1 OR NOT EXISTS (
        SELECT 1 FROM public.routing_edges_static
        WHERE src=local_node AND dst=normal_node AND door_id IS NULL
          AND public.fn_route_line_valid_inside_area(991610001,geom,0.10)
    ) THEN RAISE EXCEPTION 'One-sided point must have exactly one safe intra-area edge'; END IF;

    IF NOT EXISTS (SELECT 1 FROM public.fn_route_multifloor_edges(now(),'both','walk',0::smallint,0::smallint,
        local_point,normal_point,1,local_node,normal_node)) OR NOT EXISTS (
        SELECT 1 FROM public.fn_route_multifloor_edges(now(),'both','walk',0::smallint,0::smallint,
            normal_point,local_point,1,normal_node,local_node)
    ) THEN RAISE EXCEPTION 'Exact routing to or from the one-sided DAP failed'; END IF;

    UPDATE public.doors SET is_open=false WHERE id=991610012;
    IF EXISTS (SELECT 1 FROM public.fn_routing_edges_live_param(now(),'both','walk',0::smallint)
        WHERE local_node IN (src,dst)) THEN RAISE EXCEPTION 'Closed one-sided door leaked into the live graph'; END IF;
END;
$check$;

ROLLBACK;
