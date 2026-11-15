-- Run against the isolated routing integration database after the private area migration.
BEGIN;

INSERT INTO public.routing_floors(floor,label,sort_order) VALUES (0,'Ground',0)
ON CONFLICT (floor) DO NOTHING;

INSERT INTO public.areas(id,geom,area_type,floor,allowed_gender,is_closed,attrs) VALUES
    (991401001, ST_Multi(ST_MakeEnvelope(700000,4000000,700030,4000020,32640)),
        'riwaq',0,'both',false,'{"routing_role":"routable"}'),
    (991401002, ST_Multi(ST_MakeEnvelope(700003,4000020,700027,4000025,32640)),
        'riwaq',0,'both',true,'{"access_policy":{"public_access":false}}'),
    (991401003, ST_Multi(ST_MakeEnvelope(700033,4000007,700039,4000013,32640)),
        'riwaq',0,'both',true,'{"access_policy":{"public_access":false}}'),
    (991401004, ST_Multi(ST_MakeEnvelope(700000,3999990,700030,4000000,32640)),
        'riwaq',0,'both',false,'{"routing_role":"routable"}');

-- The DAP coordinates intentionally differ from the raw, display-only lines.
INSERT INTO public.doors(id,geom,from_area,to_area,floor,allowed_gender,is_open,modes,bidirectional,attrs)
SELECT d.id, ST_MakeLine(
        ST_SetSRID(ST_MakePoint(d.x,4000019.6),32640),
        ST_SetSRID(ST_MakePoint(d.x,4000019.8),32640)),
    991401001,991401002,0,'both',true,ARRAY['walk'],true,'{"graph":{"status":"ready"}}'
FROM (VALUES (991401001,700005),(991401002,700025)) d(id,x);

INSERT INTO public.door_access_points(id,door_id,geom,floor,from_area,to_area,confidence,build_method,needs_review)
SELECT d.id,d.id,ST_SetSRID(ST_MakePoint(d.x,4000020),32640),0,
    991401001,991401002,1,'private_area_endpoint_test',false
FROM (VALUES (991401001,700005),(991401002,700025)) d(id,x);

INSERT INTO public.doors(id,geom,from_area,to_area,floor,allowed_gender,is_open,modes,bidirectional,attrs)
VALUES (991401003,ST_GeomFromText('LINESTRING(700015 3999999.9,700015 4000000.1)',32640),
    991401001,991401004,0,'both',true,ARRAY['walk'],true,'{"graph":{"status":"ready"}}');

INSERT INTO public.door_access_points(id,door_id,geom,floor,from_area,to_area,confidence,build_method,needs_review)
VALUES (991401003,991401003,ST_GeomFromText('POINT(700015 4000000)',32640),
    0,991401001,991401004,1,'private_area_endpoint_test',false);

INSERT INTO public.routing_nodes(id,floor,geom,kind,ref_table,ref_id,area_id)
SELECT p.id,p.floor,p.geom,'door_access','door_access_points',p.id,p.from_area
FROM public.door_access_points p WHERE p.id IN (991401001,991401002,991401003);

INSERT INTO public.routing_edges_static(id,floor,src,dst,geom,base_cost)
SELECT t.id,s.floor,s.id,t.id,ST_MakeLine(s.geom,t.geom),ST_Distance(s.geom,t.geom)
FROM public.routing_nodes s CROSS JOIN public.routing_nodes t
WHERE s.id=991401003 AND t.id IN (991401001,991401002);

REFRESH MATERIALIZED VIEW public.mv_area_door_stats;

DO $check$
DECLARE
    portal record;
    origin geometry := ST_GeomFromText('POINT(700005 4000016)',32640);
BEGIN
    SELECT * INTO STRICT portal
    FROM public.fn_route_private_area_edges(now(),'both','walk',0::smallint,0::smallint,origin,991401002,NULL)
    WHERE to_node=-2;
    IF portal.terminal_kind IS DISTINCT FROM 'connection' OR portal.from_node<>991401001 THEN
        RAISE EXCEPTION 'Expected the first, cheaper connection';
    END IF;

    UPDATE public.doors SET is_open=false WHERE id=991401001;
    SELECT * INTO STRICT portal
    FROM public.fn_route_private_area_edges(now(),'both','walk',0::smallint,0::smallint,origin,991401002,NULL)
    WHERE to_node=-2;
    IF portal.terminal_kind IS DISTINCT FROM 'connection' OR portal.from_node<>991401002 THEN
        RAISE EXCEPTION 'Expected the other connection after closure';
    END IF;

    SELECT * INTO STRICT portal
    FROM public.fn_route_private_area_edges(now(),'both','walk',0::smallint,0::smallint,origin,991401003,NULL)
    WHERE to_node=-2;
    IF portal.terminal_kind IS DISTINCT FROM 'wall' OR portal.edge_id<>991401002
        OR NOT ST_DWithin(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(portal.to_point),4326),32640),
            (SELECT ST_ClosestPoint(e.geom,ST_Boundary(a.geom))
             FROM public.routing_edges_static e CROSS JOIN public.areas a
             WHERE e.id=991401002 AND a.id=991401003),0.001) THEN
        RAISE EXCEPTION 'Expected the closest point on the public edge';
    END IF;
END
$check$;

ROLLBACK;
