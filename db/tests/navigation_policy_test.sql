BEGIN;
DO $test$
BEGIN
    IF (SELECT navigation_policy->>'gps_enabled' FROM public.routing_timing_settings) <> 'false'
        OR (SELECT (navigation_policy->>'warning_uncertainty_m')::numeric FROM public.routing_timing_settings) <> 20 THEN
        RAISE EXCEPTION 'Unsafe default navigation policy';
    END IF;
    IF public.fn_routing_walk_speed() <> 1.2 THEN RAISE EXCEPTION 'Wrong default walking speed'; END IF;
    UPDATE public.routing_timing_settings SET walk_speed_mps = 0.8;
    IF public.fn_routing_walk_speed() <> 0.8 THEN RAISE EXCEPTION 'Walking speed did not persist'; END IF;
    UPDATE public.routing_timing_settings SET navigation_policy = jsonb_set(navigation_policy, '{warning_uncertainty_m}', '25');
    IF (SELECT (navigation_policy->>'warning_uncertainty_m')::numeric FROM public.routing_timing_settings) <> 25 THEN
        RAISE EXCEPTION 'Navigation setting did not persist';
    END IF;
END;
$test$;
ROLLBACK;
