-- Synchronize the Rauza Munawwara POI with its saved main group.
-- Depends on the known POI and category IDs and their current assignments.
-- Updates only poi_points.category_leaf_id on the verified legacy row.
-- Routing impact: none. Restore the captured row's category if a rollback is required.

BEGIN;

UPDATE public.poi_points AS p
SET category_leaf_id = c.id
FROM public.categories AS c
WHERE p.id = 890
    AND p.category_leaf_id = 92
    AND p.attrs #>> '{grouping,group_id}' = '106'
    AND p.attrs #>> '{grouping,sub_group_id}' IS NULL
    AND c.id = 106
    AND c.code = 'rozemonavare'
    AND c.parent_id IS NULL
    AND c.level = 1
    AND c.is_active;

COMMIT;
