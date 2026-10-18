<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CulturalTimestampSqlPackagingTest extends TestCase
{
    public function test_artisan_sql_copies_match_the_canonical_versioned_changes(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['up', 'down'] as $direction) {
            $name = '20261018_160000_cultural_content_created_at.'.$direction.'.sql';
            $this->assertFileEquals($root.'/db/changes/'.$name, $root.'/src/database/sql/'.$name);
        }
    }
}
