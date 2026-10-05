<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Jobs\RebuildDoorGraphJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real PostgreSQL, database queue and database unique locks: Queue::fake()
 * or an array cache would hide the transaction failure being regressed.
 */
class DoorGraphDispatchTest extends TestCase
{
    private array $areaIds = [];
    private array $doorIds = [];
    private array $lockTransactionLevels = [];
    private string $cachePrefix;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires the isolated PostgreSQL integration database.');
        }
        $this->assertSame('golden_path_connector_test', DB::connection()->getDatabaseName());
        $this->databaseReady = true;
        $this->cachePrefix = 'door-graph-regression-' . Str::uuid() . ':';
        config([
            'cache.default' => 'database',
            'cache.prefix' => $this->cachePrefix,
            'cache.stores.database.connection' => 'pgsql',
            'cache.stores.database.lock_connection' => 'pgsql',
            'cache.stores.database.table' => 'cache',
            'cache.stores.database.lock_table' => 'cache_locks',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'pgsql',
            'queue.connections.database.queue' => 'graph',
            'queue.connections.database.after_commit' => false,
        ]);
        Cache::forgetDriver('database');
        $this->withoutMiddleware(AdminAuth::class);

        // Deliberately no enclosing test transaction: these tests must observe
        // real outermost commits and rolled-back afterCommit callbacks.
        foreach ([500000, 500020] as $x) {
            $row = DB::selectOne("INSERT INTO areas (geom, area_type, floor)
                VALUES (ST_Multi(ST_MakeEnvelope(?,4000000,?,4000020,32640)),
                    'courtyard',0) RETURNING id", [$x, $x + 20]);
            $this->areaIds[] = (int) $row->id;
        }
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection): void {
            if (str_contains(strtolower($sql), 'insert into "cache_locks"')) {
                $this->lockTransactionLevels[] = $connection->transactionLevel();
            }
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->databaseReady) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                // Only remove fixtures owned by this test. Never truncate the
                // database; creation failures may have no response door ID.
                $ids = array_unique(array_merge($this->doorIds,
                    DB::table('door_access_points')->whereIn('from_area', $this->areaIds)
                        ->orWhereIn('to_area', $this->areaIds)->pluck('door_id')->all()));
                foreach ($ids as $id) {
                    DB::table('jobs')->whereRaw("payload::jsonb #>> '{data,commandName}' = ?", [RebuildDoorGraphJob::class])
                        ->where('payload', 'like', '%i:' . $id . ';%')->delete();
                }
                foreach (['i18n_texts', 'access_time_restrictions', 'access_prayer_restrictions'] as $table) {
                    DB::table($table)->where('entity_table', 'doors')->whereIn('entity_id', $ids)->delete();
                }
                DB::table('door_access_points')->whereIn('door_id', $ids)->delete();
                DB::table('doors')->whereIn('id', $ids)->delete();
                DB::table('areas')->whereIn('id', $this->areaIds)->delete();
                if (isset($this->cachePrefix)) {
                    DB::table('cache_locks')->where('key', 'like', $this->cachePrefix . '%')->delete();
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    private function point(): array
    {
        return ['x' => 500020, 'y' => 4000010, 'floor' => 0];
    }

    private function info(string $title = 'Test connection'): array
    {
        return [
            'basic_info' => ['title' => ['fa' => $title]],
            'operational' => [
                'status' => 'active', 'place_function' => 'connection',
                'transport_modes' => ['walk', 'wheelchair'], 'gender_access' => ['both'],
            ],
            'time_restrictions' => [], 'prayer_restrictions' => [],
        ];
    }

    private function createWithInfo(): array
    {
        $response = $this->postJson('/api/v1/doors/with-info', [
            'point' => $this->point(), 'info' => $this->info(),
        ])->assertCreated()->assertJsonPath('graph_status', 'queued');
        $data = $response->json();
        $this->doorIds[] = (int) $data['door']['id'];
        return $data;
    }

    private function jobsFor(int $doorId): array
    {
        $jobs = [];
        foreach (DB::table('jobs')->where('queue', 'graph')->get() as $row) {
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            if (($payload['data']['commandName'] ?? null) !== RebuildDoorGraphJob::class) {
                continue;
            }
            $job = unserialize($payload['data']['command'], ['allowed_classes' => [RebuildDoorGraphJob::class]]);
            if ($job->doorId === $doorId) {
                $jobs[] = $job;
            }
        }
        return $jobs;
    }

    private function lockCount(): int
    {
        return DB::table('cache_locks')->where('key', 'like', $this->cachePrefix . '%')->count();
    }

    public function test_with_info_dispatches_once_only_after_outermost_commit(): void
    {
        DB::beginTransaction();
        $data = $this->createWithInfo();
        $id = (int) $data['door']['id'];
        $this->assertSame(1, DB::transactionLevel());
        $this->assertCount(0, $this->jobsFor($id));
        $this->assertSame(0, $this->lockCount());
        $this->assertSame([], $this->lockTransactionLevels);
        $this->assertDatabaseHas('i18n_texts', ['entity_table' => 'doors', 'entity_id' => $id, 'txt' => 'Test connection']);
        $this->assertDatabaseHas('door_access_points', ['id' => $data['door_access_point']['id'], 'door_id' => $id]);
        DB::commit();
        $this->assertCount(1, $this->jobsFor($id));
        $this->assertSame(1, $this->lockCount());
        // Checks the number of dispatch attempts as well as the final job count.
        $this->assertSame([0], $this->lockTransactionLevels);
        $this->assertDatabaseHas('doors', ['id' => $id, 'is_open' => true]);
    }

    public function test_outer_rollback_discards_job_lock_door_and_dap(): void
    {
        DB::beginTransaction();
        $data = $this->createWithInfo();
        $id = (int) $data['door']['id'];
        $this->assertCount(0, $this->jobsFor($id));
        $this->assertSame(0, $this->lockCount());
        DB::rollBack();
        DB::transaction(static fn () => DB::selectOne('SELECT 1'));
        $this->assertDatabaseMissing('doors', ['id' => $id]);
        $this->assertDatabaseMissing('door_access_points', ['id' => $data['door_access_point']['id']]);
        $this->assertDatabaseMissing('i18n_texts', ['entity_table' => 'doors', 'entity_id' => $id]);
        $this->assertCount(0, $this->jobsFor($id));
        $this->assertSame(0, $this->lockCount());
        $this->assertSame([], $this->lockTransactionLevels);
    }

    public function test_invalid_info_rolls_back_creation_without_leaking_a_dispatch(): void
    {
        $before = DB::table('doors')->count();
        $dapBefore = DB::table('door_access_points')->count();
        $jobsBefore = DB::table('jobs')->count();
        $this->postJson('/api/v1/doors/with-info', [
            'point' => $this->point(), 'info' => ['basic_info' => ['title' => ['fa' => '']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('basic_info.title.fa');
        DB::transaction(static fn () => DB::selectOne('SELECT 1'));
        $this->assertSame($before, DB::table('doors')->count());
        $this->assertSame($dapBefore, DB::table('door_access_points')->count());
        $this->assertSame($jobsBefore, DB::table('jobs')->count());
        $this->assertSame(0, $this->lockCount());
        $this->assertSame([], $this->lockTransactionLevels);
    }

    public function test_standalone_store_still_dispatches_a_graph_job(): void
    {
        $data = $this->postJson('/api/v1/doors', $this->point())->assertStatus(202)->json();
        $id = (int) $data['door']['id'];
        $this->doorIds[] = $id;
        $this->assertCount(1, $this->jobsFor($id));
        $this->assertSame(1, $this->lockCount());
        $this->assertSame([0], $this->lockTransactionLevels);
    }

    public function test_standalone_store_in_outer_transaction_defers_unique_lock(): void
    {
        DB::beginTransaction();
        $data = $this->postJson('/api/v1/doors', $this->point())->assertStatus(202)->json();
        $id = (int) $data['door']['id'];
        $this->doorIds[] = $id;
        $this->assertCount(0, $this->jobsFor($id));
        $this->assertSame(0, $this->lockCount());
        DB::commit();
        $this->assertCount(1, $this->jobsFor($id));
        $this->assertSame([0], $this->lockTransactionLevels);
    }

    public function test_edit_with_existing_unique_lock_does_not_abort_transaction(): void
    {
        $data = $this->createWithInfo();
        $id = (int) $data['door']['id'];
        $this->assertCount(1, $this->jobsFor($id));
        $this->assertSame([0], $this->lockTransactionLevels);
        DB::beginTransaction();
        $this->putJson('/api/v1/doors/' . $id . '/info', $this->info('Updated connection'))
            ->assertStatus(202)->assertJsonPath('graph_status', 'queued');
        DB::commit();
        $this->assertCount(1, $this->jobsFor($id));
        $this->assertSame(1, $this->lockCount());
        $this->assertDatabaseHas('i18n_texts', ['entity_table' => 'doors', 'entity_id' => $id, 'txt' => 'Updated connection']);
        $this->assertSame([0, 0], $this->lockTransactionLevels);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS healthy')->healthy);
    }
}
