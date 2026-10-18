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
        $path = database_path('sql/20261018_220000_user_manual_activation_at.'.$direction.'.sql');
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read manual activation migration: '.$path);
        }
        DB::unprepared(preg_replace('/^(?:BEGIN|COMMIT);[ \t]*\r?$/m', '', $sql));
    }
};
