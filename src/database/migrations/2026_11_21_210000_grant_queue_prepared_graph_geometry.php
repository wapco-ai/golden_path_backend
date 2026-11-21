<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('sql/20261121_210000_queue_prepared_graph_geometry.up.sql');
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read graph queue permissions migration: '.$path);
        }

        // Artisan manages the transaction when running the standalone SQL.
        DB::unprepared(preg_replace('/^(?:BEGIN|COMMIT);[ \t]*\r?$/m', '', $sql));
    }

    public function down(): void
    {
        // The running graph worker requires these grants even on the previous release.
        // They may also have been provisioned independently of this migration.
    }
};
