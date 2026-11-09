<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/20261109_160000_enduser_feedback_delivery.up.sql')));
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/20261109_160000_enduser_feedback_delivery.down.sql')));
    }
};
