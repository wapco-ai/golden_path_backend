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
        // Production Docker mounts src only, so keep a matching SQL copy there.
        $path = database_path('sql/20261018_160000_cultural_content_created_at.'.$direction.'.sql');
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read cultural timestamp migration: '.$path);
        }
        // Artisan owns its transaction; the source SQL also supports standalone psql.
        $sql = preg_replace('/^(?:BEGIN|COMMIT);[ \t]*\r?$/m', '', $sql);
        DB::unprepared($sql);
    }
};
