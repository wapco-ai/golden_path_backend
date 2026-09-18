<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PoiSearchSqlPackagingTest extends TestCase
{
    public function test_artisan_sql_copies_match_the_canonical_versioned_changes(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['up', 'down'] as $direction) {
            $name = '20260918_160000_poi_endpoint_search.'.$direction.'.sql';
            $this->assertFileEquals(
                $root.'/db/changes/'.$name,
                $root.'/src/database/sql/'.$name
            );
        }
        // Rollback must restore the preceding function, including floor metadata.
        $this->assertFileEquals(
            $root.'/db/changes/20260917_180000_public_place_floors.up.sql',
            $root.'/db/changes/20260918_160000_poi_endpoint_search.down.sql'
        );
    }
}
