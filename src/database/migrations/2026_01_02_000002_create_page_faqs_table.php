<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE TABLE IF NOT EXISTS public.page_faqs (
              id         BIGSERIAL PRIMARY KEY,
              question   TEXT NOT NULL,
              answer     TEXT NOT NULL,
              sort_order INTEGER NOT NULL DEFAULT 0,
              is_active  BOOLEAN NOT NULL DEFAULT TRUE,
              created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            );
        SQL);
        DB::statement("CREATE INDEX IF NOT EXISTS page_faqs_active_sort_idx ON public.page_faqs(is_active, sort_order, id);");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.page_faqs;');
    }
};
