-- Purpose: remove employer identity mappings after rolling back the adapter.
-- Dependencies: matching up file; stop public logins while applying rollback.
-- Objects: public.user_auth_identities and users_mobile_auth_lookup_idx.
-- Routing impact: none.
-- Rollback strategy: up file recreates an empty mapping table.

BEGIN;
DROP INDEX IF EXISTS public.users_mobile_auth_lookup_idx;
DROP TABLE IF EXISTS public.user_auth_identities;
COMMIT;
