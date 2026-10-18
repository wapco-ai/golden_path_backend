<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SignedUserSqlPackagingTest extends TestCase
{
    public function test_packaged_sql_matches_the_immutable_source_change(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['up', 'down'] as $direction) {
            $name = '20261018_220000_user_manual_activation_at.'.$direction.'.sql';
            $this->assertFileEquals($root.'/db/changes/'.$name, $root.'/src/database/sql/'.$name);
        }
    }
}
