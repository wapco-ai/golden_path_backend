<?php

namespace Tests\Feature;

use App\Services\Admin\AdminService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPasswordLockPostgresTest extends TestCase
{
    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This concurrency check requires the isolated PostgreSQL test database.');
        }
        $this->assertSame('golden_path_connector_test', DB::connection()->getDatabaseName());

        // Confine fixtures to a fresh schema; never alter the baseline/public tables.
        $this->schema = 'admin_password_lock_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('password');
            $table->boolean('is_admin')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id(); $table->string('code'); $table->string('title');
        });
        Schema::create('admin_user_roles', function (Blueprint $table) {
            $table->integer('user_id'); $table->integer('role_id');
        });
        Schema::create('admin_auth', function (Blueprint $table) {
            $table->integer('user_id')->primary(); $table->string('password_hash');
            $table->timestamp('last_password_change_at')->nullable(); $table->timestamps();
        });
        Schema::create('admin_refresh_tokens', function (Blueprint $table) {
            $table->id(); $table->integer('user_id'); $table->timestamp('revoked_at')->nullable();
        });
        $hash = Hash::make('fixture-old-password');
        DB::table('users')->insert(['id' => 1, 'name' => 'Fixture Admin', 'password' => $hash]);
        DB::table('admin_auth')->insert(['user_id' => 1, 'password_hash' => $hash]);
        DB::table('admin_roles')->insert(['id' => 1, 'code' => 'manage_admins', 'title' => 'مدیریت ادمین ها']);
        DB::table('admin_user_roles')->insert(['user_id' => 1, 'role_id' => 1]);

        $config = config('database.connections.'.config('database.default'));
        $config['search_path'] = $this->schema;
        config(['database.connections.admin_lock_probe' => $config]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->schema !== null) {
                DB::purge('admin_lock_probe');
                DB::statement('SET search_path TO public');
                DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function accountMutations(): array
    {
        return [['manager reset'], ['self change'], ['delete'], ['roles']];
    }

    #[DataProvider('accountMutations')]
    public function test_account_is_exclusively_locked_before_credentials_are_read_or_changed(string $operation): void
    {
        $probe = DB::connection('admin_lock_probe');
        $checked = false;
        $blocked = false;
        $mainConnection = DB::connection()->getName();
        DB::listen(function (QueryExecuted $event) use ($probe, $mainConnection, &$checked, &$blocked) {
            if ($checked || $event->connectionName !== $mainConnection
                || !str_contains($event->sql, '"admin_auth"')) {
                return;
            }
            $checked = true;
            $probe->beginTransaction();
            try {
                $probe->select('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', [1]);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '55P03') {
                    throw $exception;
                }
                $blocked = true;
            } finally {
                $probe->rollBack();
            }
        });

        $service = app(AdminService::class);
        match ($operation) {
            'manager reset' => $service->updateAdmin(1, ['password' => 'fixture-new-password']),
            'self change' => $service->changeOwnPassword(1, 'fixture-old-password', 'fixture-new-password'),
            'delete' => $service->deleteAdmin(1),
            'roles' => $service->updateRoles(1, ['مدیریت ادمین ها']),
        };

        $this->assertTrue($checked, 'The test must reach the credential boundary.');
        $this->assertTrue($blocked, 'A competing password update must wait for the account transaction.');
        $probe->beginTransaction();
        try {
            $this->assertCount(1, $probe->select('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', [1]));
        } finally {
            $probe->rollBack();
        }
    }
}
