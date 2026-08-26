<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE TABLE IF NOT EXISTS public.pages (
              id         BIGSERIAL PRIMARY KEY,
              type       TEXT NOT NULL UNIQUE,
              title      TEXT NOT NULL,
              description TEXT,
              phones     TEXT[] NOT NULL DEFAULT ARRAY[]::TEXT[],
              emails     TEXT[] NOT NULL DEFAULT ARRAY[]::TEXT[],
              address    TEXT,
              created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
              CONSTRAINT pages_type_check CHECK (type IN ('support','rules','about','contact')),
              CONSTRAINT pages_phones_max3 CHECK (COALESCE(array_length(phones, 1), 0) <= 3),
              CONSTRAINT pages_emails_max3 CHECK (COALESCE(array_length(emails, 1), 0) <= 3)
            );
        SQL);
        DB::statement("CREATE INDEX IF NOT EXISTS pages_type_idx ON public.pages(type);");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.pages;');
    }
};
