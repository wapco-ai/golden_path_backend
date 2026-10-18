<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CulturalMediaSqlPackagingTest extends TestCase
{
    public function test_artisan_sql_copies_match_the_canonical_versioned_changes(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['up', 'down'] as $direction) {
            $name = '20261018_180000_cultural_landmark_images.'.$direction.'.sql';
            $this->assertFileEquals($root.'/db/changes/'.$name, $root.'/src/database/sql/'.$name);
        }
    }
}
