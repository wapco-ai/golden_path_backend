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
        // Production Docker mounts src only; keep the versioned SQL inside that mount.
        $path = database_path('sql/20261005_170000_diverse_route_alternatives.'.$direction.'.sql');
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read route alternative migration: '.$path);
        }
        // Artisan owns its transaction; standalone SQL can also be applied with psql.
        $sql = preg_replace('/^(?:BEGIN|COMMIT);[ \t]*\r?$/m', '', $sql);
        DB::unprepared($sql);
    }
};
