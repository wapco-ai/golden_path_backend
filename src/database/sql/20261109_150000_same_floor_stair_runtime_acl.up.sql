-- Extend existing routing permissions without changing default or unrelated grants.
BEGIN;

DO $acl$
DECLARE
    permission record;
BEGIN
    FOR permission IN
        SELECT f.helper, r.rolname
        FROM (VALUES
            ('public.fn_same_floor_stair_landing_areas(smallint)',
             'public.fn_allowed_areas(timestamptz,public.gender_enum,text,smallint)'::regprocedure),
            ('public.fn_same_floor_stair_access(bigint,timestamptz,public.gender_enum,text)',
             'public.fn_routing_edges_live_param(timestamptz,public.gender_enum,text,smallint)'::regprocedure)
        ) f(helper, caller)
        JOIN pg_proc p ON p.oid = f.caller
        CROSS JOIN LATERAL aclexplode(p.proacl) a
        JOIN pg_roles r ON r.oid = a.grantee
        WHERE a.privilege_type = 'EXECUTE' AND a.grantee <> p.proowner

        UNION

        SELECT f.helper, r.rolname
        FROM (VALUES
            ('public.fn_same_floor_stair_candidate(bigint,smallint,bigint,bigint,bigint,bigint)'),
            ('public.fn_same_floor_stair_edge_geom(bigint)')
        ) f(helper)
        CROSS JOIN pg_class c
        CROSS JOIN LATERAL aclexplode(c.relacl) a
        JOIN pg_roles r ON r.oid = a.grantee
        WHERE c.oid = 'public.routing_connectors'::regclass
          AND a.privilege_type = 'INSERT' AND a.grantee <> c.relowner
    LOOP
        EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO %I', permission.helper, permission.rolname);
    END LOOP;
END;
$acl$;

COMMIT;
