-- Do not silently drop existing shared generic connections on rollback.
-- Reclassify/export those connectors first; otherwise the previous CHECK would reject them.
BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.routing_connectors WHERE kind = 'connection') THEN
        RAISE EXCEPTION 'Reclassify shared kind=connection connectors before schema rollback';
    END IF;
END
$$;

ALTER TABLE public.routing_connectors
    DROP CONSTRAINT routing_connectors_kind_check,
    ADD CONSTRAINT routing_connectors_kind_check
      CHECK (kind IN ('stair', 'ramp', 'elevator', 'escalator'));

COMMIT;
