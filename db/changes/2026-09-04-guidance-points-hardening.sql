-- GoldenPath guidance-point metadata compatibility change
-- Date: 2026-09-04
-- Safe to run repeatedly on PostgreSQL 15+.
-- This change does not touch routing geometry or graph tables.

BEGIN;

ALTER TABLE public.guidance_points
    ALTER COLUMN x TYPE numeric(18,9) USING x::numeric(18,9),
    ALTER COLUMN y TYPE numeric(18,9) USING y::numeric(18,9);

ALTER TABLE public.guidance_point_images
    ADD COLUMN IF NOT EXISTS view_orientation varchar(20),
    ADD COLUMN IF NOT EXISTS azimuth_deg numeric,
    ADD COLUMN IF NOT EXISTS fov_deg numeric NOT NULL DEFAULT 60,
    ADD COLUMN IF NOT EXISTS caption varchar(160),
    ADD COLUMN IF NOT EXISTS attrs jsonb NOT NULL DEFAULT '{}'::jsonb;

CREATE INDEX IF NOT EXISTS guidance_point_images_heading_idx
    ON public.guidance_point_images (point_id, azimuth_deg);

CREATE INDEX IF NOT EXISTS guidance_point_images_sort_idx
    ON public.guidance_point_images (point_id, sort_order);

COMMIT;
