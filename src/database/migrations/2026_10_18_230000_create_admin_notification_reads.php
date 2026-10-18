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
        $path = database_path('sql/20261018_230000_admin_notification_reads.'.$direction.'.sql');
        $sql = @file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read admin notification migration: '.$path);
        }
        DB::unprepared(preg_replace('/^(?:BEGIN|COMMIT);[ \t]*\r?$/m', '', $sql));
    }
};
