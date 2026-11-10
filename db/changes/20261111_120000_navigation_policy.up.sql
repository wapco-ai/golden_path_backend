BEGIN;
ALTER TABLE public.routing_timing_settings
    ADD COLUMN navigation_policy jsonb NOT NULL DEFAULT '{"gps_enabled": false, "warning_uncertainty_m": 20, "snap_distance_m": 20, "arrival_radius_m": 3, "qr_accuracy_m": 2, "manual_accuracy_m": 5, "step_length_m": 0.7, "drift_per_meter": 0.3, "heading_max_age_ms": 5000, "measurement_max_age_ms": 15000, "route_window_m": 3, "heading_tolerance_deg": 60, "arrival_samples": 2, "arrival_dwell_ms": 500, "gps_max_accuracy_m": 25, "gps_progress_accuracy_m": 6, "gps_arrival_accuracy_m": 3}'::jsonb,
    ADD COLUMN navigation_version integer NOT NULL DEFAULT 1 CHECK (navigation_version > 0),
    ADD COLUMN navigation_updated_at timestamptz NOT NULL DEFAULT now(),
    ADD CONSTRAINT routing_navigation_policy_object CHECK (jsonb_typeof(navigation_policy) = 'object');
-- Reuse application writer roles; do not grant configuration writes to PUBLIC.
DO $acl$
DECLARE writer record;
BEGIN
    FOR writer IN
        SELECT DISTINCT r.rolname FROM pg_class c
        CROSS JOIN LATERAL aclexplode(c.relacl) a
        JOIN pg_roles r ON r.oid = a.grantee
        WHERE c.oid = 'public.routing_connectors'::regclass
          AND a.privilege_type = 'INSERT' AND a.grantee <> c.relowner
    LOOP
        EXECUTE format('GRANT SELECT, UPDATE ON public.routing_timing_settings TO %I', writer.rolname);
    END LOOP;
END;
$acl$;
COMMIT;
