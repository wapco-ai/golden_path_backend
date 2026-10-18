<?php

namespace Tests\Feature;

use App\Exceptions\PublicAuthException;
use App\Services\RazaviAuthService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/** Production PostgreSQL lookup/index coverage; all user fixtures roll back. */
class RazaviAuthPostgresTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL database.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
        config(['public_auth.provider' => 'razavi']);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) DB::rollBack();
        parent::tearDown();
    }

    private function lookup(string $mobile)
    {
        return (new ReflectionMethod(RazaviAuthService::class, 'usersWithMobile'))
            ->invoke(app(RazaviAuthService::class), $mobile);
    }

    private function insertUser(string $mobile, string $status = 'active'): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'razavi-index-'.bin2hex(random_bytes(6)),
            'mobile' => $mobile, 'is_admin' => false,
            'status' => $status, 'is_active' => $status === 'active',
        ]);
    }

    public function test_generic_prepared_lookup_uses_functional_index_with_realistic_user_volume(): void
    {
        $this->assertNotNull(DB::selectOne("SELECT to_regclass('public.users_mobile_auth_lookup_idx') AS name")->name);
        DB::statement(<<<'SQL'
            INSERT INTO public.users (name, mobile, is_admin, status, is_active)
            SELECT 'razavi-index-volume-' || value::text,
                '09900' || lpad(value::text, 6, '0'), false, 'active', true
            FROM generate_series(1, 20000) AS value
            SQL);
        $target = $this->insertUser('+98 (912) 345-6789');
        DB::statement('ANALYZE public.users');
        DB::statement('SET LOCAL plan_cache_mode = force_generic_plan');

        // Compile the real service query without disabling sequential scans or
        // otherwise forcing PostgreSQL's index choice.
        $lookup = $this->lookup('09123456789');
        $number = 0;
        $sql = preg_replace_callback('/\?/', static function () use (&$number) {
            return '$'.(++$number);
        }, $lookup->toSql());
        $statement = 'razavi_lookup_'.bin2hex(random_bytes(5));
        DB::statement('PREPARE '.$statement.' ('.implode(', ', array_fill(0, $number, 'text')).') AS '.$sql);
        try {
            $parameters = array_map(static fn ($value) => DB::connection()->getPdo()->quote($value), $lookup->getBindings());
            $plan = DB::selectOne('EXPLAIN (FORMAT JSON, ANALYZE true) EXECUTE '.$statement.' ('.implode(', ', $parameters).')');
            $json = $plan->{'QUERY PLAN'};
            $this->assertStringContainsString('users_mobile_auth_lookup_idx', $json);
            $this->assertStringContainsString('$1', $json, 'The index must work with a generic parameterized plan.');
            $this->assertSame([$target], $lookup->pluck('id')->all());
        } finally {
            DB::statement('DEALLOCATE '.$statement);
        }
    }

    public function test_production_expression_keeps_formatting_controls_and_duplicate_rejection(): void
    {
        foreach (['0912 345 6789', '+98 (912) 345-6789', '۰۹۱۲ ۳۴۵ ۶۷۸۹', "0912\u{00A0}345\u{00A0}6789"] as $mobile) {
            $id = $this->insertUser($mobile, 'disabled');
            $this->assertSame([$id], $this->lookup('09123456789')->pluck('id')->all());
            try {
                app(RazaviAuthService::class)->requestOtp('09123456789');
                $this->fail('Formatted disabled accounts must not receive OTP.');
            } catch (PublicAuthException $exception) {
                $this->assertSame('ACCOUNT_LOCKED', $exception->errorCode);
            }
            DB::table('users')->where('id', $id)->delete();
        }

        $first = $this->insertUser('09123456789');
        $second = $this->insertUser('+98 (912) 345-6789');
        $this->assertEqualsCanonicalizing([$first, $second], $this->lookup('09123456789')->pluck('id')->all());
        try {
            app(RazaviAuthService::class)->requestOtp('09123456789');
            $this->fail('The nonunique index must preserve ambiguous-account rejection.');
        } catch (PublicAuthException $exception) {
            $this->assertSame('IDENTITY_CONFLICT', $exception->errorCode);
        }
    }
}
