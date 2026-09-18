<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->applySql('up');
    }

    public function down(): void
    {
        $this->applySql('down');
    }

    private function applySql(string $direction): void
    {
        $path = base_path('../db/changes/20260918_160000_poi_endpoint_search.'.$direction.'.sql');
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read POI search migration: '.$path);
        }

        // Artisan owns the transaction; the standalone files also work with psql.
        $sql = preg_replace('/^(?:BEGIN|COMMIT);[ \t]*\r?$/m', '', $sql);
        DB::unprepared($sql);
    }
};
