-- Allow a shared multi-floor connector to be reclassified as a generic connection.
-- Ordinary same-floor connections remain independent doors/DAPs.
-- Existing connector IDs, stops, DAP geometry, graph nodes and routes are not rewritten.
-- Deploy this schema change before deploying the backend/frontend API changes.
BEGIN;

ALTER TABLE public.routing_connectors
    DROP CONSTRAINT routing_connectors_kind_check,
    ADD CONSTRAINT routing_connectors_kind_check
      CHECK (kind IN ('stair', 'ramp', 'elevator', 'escalator', 'connection'));

COMMIT;
