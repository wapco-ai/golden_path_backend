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
        $path = database_path('sql/20261111_150000_optimize_route_endpoints.'.$direction.'.sql');
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read endpoint performance migration: '.$path);
        }

        // Artisan owns its transaction. Standalone psql uses the SQL transaction.
        DB::unprepared(preg_replace('/^(?:BEGIN|COMMIT);[ \t]*\r?$/m', '', $sql));
    }
};
