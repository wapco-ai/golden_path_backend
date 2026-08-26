<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * GOLDEN_PATH_TEST_DB_GUARD
     *
     * IMPORTANT:
     * This preflight runs BEFORE parent::setUp().
     *
     * RefreshDatabase may execute database migrations during parent::setUp(),
     * therefore checking the database only after parent::setUp() is too late.
     */
    protected function setUp(): void
    {
        $this->assertSafeTestProcessEnvironment();

        parent::setUp();

        $this->assertSafeBootedApplicationDatabase();
    }

    private function assertSafeTestProcessEnvironment(): void
    {
        $appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? null);
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null);
        $database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? null);

        if ($appEnv !== 'testing') {
            throw new RuntimeException(
                'Refusing to bootstrap tests: APP_ENV must be [testing].'
            );
        }

        if ($database === 'golden_path') {
            throw new RuntimeException(
                'Refusing to bootstrap tests against database [golden_path].'
            );
        }

        if ($connection === 'pgsql' && empty($database)) {
            throw new RuntimeException(
                'Refusing PostgreSQL tests without an explicit test database.'
            );
        }

        if ($connection === 'sqlite' && $database !== ':memory:') {
            throw new RuntimeException(
                'SQLite tests must use database [:memory:].'
            );
        }
    }

    private function assertSafeBootedApplicationDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'Booted Laravel environment must be [testing].'
            );
        }

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($database === 'golden_path') {
            throw new RuntimeException(
                'Booted Laravel configuration points to [golden_path].'
            );
        }

        if ($connection === 'sqlite' && $database !== ':memory:') {
            throw new RuntimeException(
                'Booted SQLite test connection must use [:memory:].'
            );
        }
    }
}
