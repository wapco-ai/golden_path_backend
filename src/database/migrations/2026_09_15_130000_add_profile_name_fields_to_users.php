<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'first_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('first_name', 100)->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'last_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('last_name', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        $columns = [];

        if (Schema::hasColumn('users', 'first_name')) {
            $columns[] = 'first_name';
        }

        if (Schema::hasColumn('users', 'last_name')) {
            $columns[] = 'last_name';
        }

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
