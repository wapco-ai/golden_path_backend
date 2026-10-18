<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CategoryTimestampSqlPackagingTest extends TestCase
{
    public function test_packaged_timestamp_sql_matches_the_canonical_change(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['up', 'down'] as $direction) {
            $name = '20261018_200000_category_created_at.'.$direction.'.sql';
            $this->assertFileEquals($root.'/db/changes/'.$name, $root.'/src/database/sql/'.$name);
        }
    }
}
