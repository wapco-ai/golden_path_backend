-- Manual production data repair. The POI identifiers are production-specific.
-- Execute with psql -v ON_ERROR_STOP=1. Existing matching rows are left intact.
BEGIN;
SET LOCAL lock_timeout = '5s';
LOCK TABLE public.featured_landmark_places IN SHARE ROW EXCLUSIVE MODE;

INSERT INTO public.featured_landmark_places (poi_id, sort_order, is_active)
SELECT expected.poi_id, expected.sort_order, true
FROM (VALUES (1006, 1), (962, 3), (934, 4)) AS expected(poi_id, sort_order)
WHERE NOT EXISTS (
    SELECT 1
    FROM public.featured_landmark_places AS current
    WHERE current.poi_id = expected.poi_id
);

DO $$
DECLARE
    actual_ids text[];
    all_have_images_on_ground boolean;
BEGIN
    IF (
        SELECT count(*)
        FROM public.featured_landmark_places
        WHERE is_active AND (poi_id, sort_order) IN ((1006, 1), (962, 3), (934, 4))
    ) <> 3 THEN
        RAISE EXCEPTION 'Unexpected production featured landmark configuration';
    END IF;

    SELECT array_agg(item->>'id' ORDER BY position),
           bool_and(item->>'floor' = '0' AND COALESCE(btrim(item->>'image'), '') <> '')
    INTO actual_ids, all_have_images_on_ground
    FROM jsonb_array_elements(
        public.fn_landmark_places_json('fa'::public.lang_enum, 20, NULL, NULL, NULL, NULL, true)
            #> '{places,landmarkPlaces}'
    ) WITH ORDINALITY AS featured(item, position);

    IF actual_ids IS DISTINCT FROM ARRAY['1006', '962', '934']
        OR all_have_images_on_ground IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'Unexpected featured landmark response: ids %, ground-floor images %',
            actual_ids, all_have_images_on_ground;
    END IF;
END $$;

COMMIT;
