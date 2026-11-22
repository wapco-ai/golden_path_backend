<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/20261122_100000_edit_area_types.up.sql')));
    }

    public function down(): void
    {
        // Older clients can use the existing API with this additive enum and graph function.
        // PostgreSQL cannot drop one enum value without replacing the type and its dependants.
    }
};
