<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guidance_points')) {
            // x/y are API WGS84 longitude/latitude convenience columns. The original
            // migration used only three decimal places, which is too coarse for
            // navigation. geom remains the canonical EPSG:32640 spatial column.
            DB::statement(
                'ALTER TABLE public.guidance_points '
                . 'ALTER COLUMN x TYPE numeric(18,9) USING x::numeric(18,9), '
                . 'ALTER COLUMN y TYPE numeric(18,9) USING y::numeric(18,9)'
            );
        }

        if (!Schema::hasTable('guidance_point_images')) {
            return;
        }

        if (!Schema::hasColumn('guidance_point_images', 'view_orientation')) {
            Schema::table('guidance_point_images', function (Blueprint $table) {
                $table->string('view_orientation', 20)->nullable();
            });
        }

        if (!Schema::hasColumn('guidance_point_images', 'azimuth_deg')) {
            Schema::table('guidance_point_images', function (Blueprint $table) {
                $table->decimal('azimuth_deg', 6, 2)->nullable();
            });
        }

        if (!Schema::hasColumn('guidance_point_images', 'fov_deg')) {
            Schema::table('guidance_point_images', function (Blueprint $table) {
                $table->decimal('fov_deg', 6, 2)->default(60);
            });
        }

        if (!Schema::hasColumn('guidance_point_images', 'caption')) {
            Schema::table('guidance_point_images', function (Blueprint $table) {
                $table->string('caption', 160)->nullable();
            });
        }

        if (!Schema::hasColumn('guidance_point_images', 'attrs')) {
            Schema::table('guidance_point_images', function (Blueprint $table) {
                $table->jsonb('attrs')->default(DB::raw("'{}'::jsonb"));
            });
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS guidance_point_images_heading_idx '
            . 'ON public.guidance_point_images (point_id, azimuth_deg)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS guidance_point_images_sort_idx '
            . 'ON public.guidance_point_images (point_id, sort_order)'
        );
    }

    public function down(): void
    {
        // Compatibility migration: these columns already exist in some deployed
        // databases outside Laravel migration history. A destructive rollback could
        // remove pre-existing production schema, so down() is intentionally a no-op.
    }
};
