-- Remove the explicit-node overload; the coordinate solver is retained.
BEGIN;
DROP FUNCTION public.fn_route_multifloor_edges(timestamptz,public.gender_enum,text,smallint,smallint,geometry,geometry,integer,bigint,bigint);
COMMIT;
