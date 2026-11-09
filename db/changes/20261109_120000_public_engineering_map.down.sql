-- Purpose: remove the public engineering map vector tile function.
-- Dependencies: no downstream database objects.
-- Object: public.fn_public_engineering_map_mvt(integer,integer,integer,smallint,public.lang_enum).
-- Routing impact: none.
-- Rollback: reapply the matching up migration.

BEGIN;

DROP FUNCTION IF EXISTS public.fn_public_engineering_map_mvt(integer, integer, integer, smallint, public.lang_enum);

COMMIT;
