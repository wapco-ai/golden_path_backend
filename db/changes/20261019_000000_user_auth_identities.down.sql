-- Purpose: remove employer identity mappings after rolling back the adapter.
-- Dependencies: matching up file; stop public logins while applying rollback.
-- Objects: public.user_auth_identities only; users and local sessions retained.
-- Routing impact: none.
-- Rollback strategy: up file recreates an empty mapping table.

BEGIN;
DROP TABLE IF EXISTS public.user_auth_identities;
COMMIT;
