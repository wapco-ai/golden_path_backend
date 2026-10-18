-- Purpose: record creation dates for future category and subcategory rows only.
-- Dependencies: public.categories from the immutable baseline.
-- Objects: public.categories.created_at (nullable timestamptz).
-- Routing impact: none; category IDs, hierarchy, labels and geometry are unchanged.
-- Rollback: revert API code first, then apply the matching down file if needed.
BEGIN;

-- Do not set a default while adding the column: legacy dates are unknown.
ALTER TABLE public.categories
    ADD COLUMN IF NOT EXISTS created_at timestamp with time zone NULL;

ALTER TABLE public.categories
    ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP;

COMMIT;
