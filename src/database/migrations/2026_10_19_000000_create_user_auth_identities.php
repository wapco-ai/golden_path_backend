<?php

use App\Support\IranianMobile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_auth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32);
            // Keep development and production UUID namespaces separate.
            $table->string('issuer', 255);
            $table->string('subject', 64);
            $table->timestamps();
            $table->unique(['provider', 'issuer', 'subject'], 'user_auth_identity_subject_unique');
            $table->unique(['provider', 'issuer', 'user_id'], 'user_auth_identity_user_unique');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS users_mobile_auth_lookup_idx ON public.users (('.IranianMobile::postgresStorageExpression().'))');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS public.users_mobile_auth_lookup_idx');
        }
        Schema::dropIfExists('user_auth_identities');
    }
};
