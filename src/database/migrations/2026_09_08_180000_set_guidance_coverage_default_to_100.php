<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only the default for future inserts changes. Preserve all saved radii.
        DB::statement('ALTER TABLE public.guidance_points ALTER COLUMN coverage_radius_m SET DEFAULT 100.00');
    }

    public function down(): void
    {
        // Reverting the default must not rewrite values entered by administrators.
        DB::statement('ALTER TABLE public.guidance_points ALTER COLUMN coverage_radius_m SET DEFAULT 10.00');
    }
};
