<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Deployment packaging checks only; these tests never connect to a database. */
class AdminNotificationSqlPackagingTest extends TestCase
{
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_artisan_sql_is_an_exact_copy_of_each_versioned_change(): void
    {
        foreach (['up', 'down'] as $direction) {
            $name = '20261018_230000_admin_notification_reads.'.$direction.'.sql';
            $this->assertFileEquals(
                $this->repositoryRoot().'/db/changes/'.$name,
                $this->repositoryRoot().'/src/database/sql/'.$name,
            );
        }
    }

    public function test_up_sql_declares_per_admin_receipts_with_database_enforced_identity_and_time(): void
    {
        $path = $this->repositoryRoot().'/src/database/sql/20261018_230000_admin_notification_reads.up.sql';
        $this->assertFileExists($path);
        $sql = strtolower((string) file_get_contents($path));
        $sql = preg_replace('/--[^\r\n]*/', '', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);

        $this->assertMatchesRegularExpression('/create table if not exists (?:public\.)?admin_notification_reads\s*\(/', $sql);
        $this->assertMatchesRegularExpression('/primary key\s*\(\s*admin_user_id\s*,\s*notification_type\s*,\s*entity_id\s*\)/', $sql);
        $this->assertMatchesRegularExpression('/references\s+(?:public\.)?users\s*\(\s*id\s*\)\s+on delete cascade/', $sql);
        $this->assertMatchesRegularExpression('/admin_user_id\s+bigint\s+not null/', $sql);
        $this->assertMatchesRegularExpression('/entity_id\s+bigint\s+not null/', $sql);
        $this->assertMatchesRegularExpression('/check\s*\(\s*entity_id\s*>\s*0\s*\)/', $sql);
        $this->assertMatchesRegularExpression('/check\s*\(\s*notification_type\s+in\s*\(\s*\x27new_user\x27\s*,\s*\x27new_comment\x27\s*,\s*\x27support_message\x27\s*\)\s*\)/', $sql);
        $this->assertMatchesRegularExpression('/read_at\s+(?:timestamptz|timestamp with time zone)\s+not null\s+default\s+(?:current_timestamp|now\(\)|clock_timestamp\(\))/', $sql);
    }

    public function test_down_sql_is_idempotent_and_drops_only_the_receipt_table(): void
    {
        $path = $this->repositoryRoot().'/src/database/sql/20261018_230000_admin_notification_reads.down.sql';
        $this->assertFileExists($path);
        $sql = strtolower((string) file_get_contents($path));
        $sql = preg_replace('/--[^\r\n]*/', '', $sql);
        $this->assertMatchesRegularExpression('/drop\s+table\s+if\s+exists\s+(?:public\.)?admin_notification_reads\s*;/', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bcascade\b|\bdrop\s+(?:schema|database)\b/', $sql);
    }

    public function test_migration_reads_packaged_sql_and_explicitly_fails_when_sql_is_missing(): void
    {
        $path = $this->repositoryRoot().'/src/database/migrations/2026_10_18_230000_create_admin_notification_reads.php';
        $this->assertFileExists($path);
        $migration = (string) file_get_contents($path);

        $this->assertStringContainsString("database_path('sql/20261018_230000_admin_notification_reads.", $migration);
        $this->assertStringContainsString('file_get_contents(', $migration);
        $this->assertMatchesRegularExpression('/throw new RuntimeException\s*\(/', str_replace('\\RuntimeException', 'RuntimeException', $migration));
        $this->assertStringContainsString('DB::unprepared(', $migration);
        $this->assertMatchesRegularExpression('/applySql\(\x27up\x27\)/', $migration);
        $this->assertMatchesRegularExpression('/applySql\(\x27down\x27\)/', $migration);
    }
}
