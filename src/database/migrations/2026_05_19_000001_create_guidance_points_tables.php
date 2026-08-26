<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guidance_points', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->smallInteger('floor');
            $table->unsignedBigInteger('area_id')->nullable(); // optional logical tag only; no FK by design
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->decimal('x', 12, 3);
            $table->decimal('y', 13, 3);
            $table->string('view_direction', 40)->nullable();
            $table->decimal('azimuth_deg', 6, 2)->nullable();
            $table->decimal('coverage_radius_m', 8, 2)->default(10.00);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('primary_image_url', 2048)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['floor', 'is_active', 'deleted_at'], 'guidance_points_public_idx');
            $table->index(['floor', 'area_id', 'sort_order'], 'guidance_points_area_sort_idx');
            $table->index('created_by', 'guidance_points_created_by_idx');
            $table->index('updated_by', 'guidance_points_updated_by_idx');
        });

        DB::statement("ALTER TABLE guidance_points ADD CONSTRAINT guidance_points_floor_chk CHECK (floor IN (-1, 0))");
        DB::statement("ALTER TABLE guidance_points ADD CONSTRAINT guidance_points_azimuth_chk CHECK (azimuth_deg IS NULL OR (azimuth_deg >= 0 AND azimuth_deg < 360))");
        DB::statement("ALTER TABLE guidance_points ADD CONSTRAINT guidance_points_radius_chk CHECK (coverage_radius_m > 0 AND coverage_radius_m <= 100)");
        DB::statement("SELECT AddGeometryColumn('public', 'guidance_points', 'geom', 32640, 'POINT', 2)");
        DB::statement("CREATE INDEX guidance_points_geom_gix ON guidance_points USING GIST (geom)");

        Schema::create('guidance_point_images', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('point_id');
            $table->string('image_url', 2048);
            $table->string('image_key', 1024);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestampsTz();

            $table->foreign('point_id')
                ->references('id')
                ->on('guidance_points')
                ->cascadeOnDelete();

            $table->unique(['point_id', 'sort_order'], 'guidance_point_images_sort_uq');
            $table->index('point_id', 'guidance_point_images_point_idx');
        });

        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 80);
            $table->string('entity_table', 120);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['entity_table', 'entity_id'], 'admin_activity_logs_entity_idx');
            $table->index(['user_id', 'created_at'], 'admin_activity_logs_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
        Schema::dropIfExists('guidance_point_images');
        Schema::dropIfExists('guidance_points');
    }
};
