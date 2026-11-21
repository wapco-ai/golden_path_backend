<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GraphWorkerMigrationGrantsTest extends TestCase
{
    public function test_worker_can_call_recreated_helpers_after_an_ordinary_migration(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL with PostGIS');
        }
        $this->assertSame('golden_path_connector_test', DB::connection()->getDatabaseName());
        $directory = sys_get_temp_dir().'/graph-acl-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory);
        DB::beginTransaction();
        try {
            DB::statement('CREATE ROLE gp_queue_runtime NOLOGIN');
            DB::statement('CREATE ROLE gp_graph_acl_test_reader NOLOGIN');
            $helpers = [
                'public.fn_build_intra_area_edge_prepared(bigint,smallint,geometry,geometry,double precision,geometry,geometry,geometry,geometry,geometry,geometry)',
                'public.fn_route_line_inside_prepared(geometry,geometry,geometry,double precision)',
                'public.fn_route_visibility_prepared(bigint,geometry,geometry,geometry,geometry)',
            ];
            // The integration baseline predates the prepared geometry helpers.
            foreach ($helpers as $helper) {
                DB::statement("CREATE FUNCTION $helper RETURNS integer LANGUAGE sql AS 'SELECT 1'");
                DB::statement("REVOKE EXECUTE ON FUNCTION $helper FROM PUBLIC");
                DB::statement("GRANT EXECUTE ON FUNCTION $helper TO gp_queue_runtime");
            }
            $fixture = <<<'PHP'
                <?php
                use Illuminate\Database\Migrations\Migration;
                use Illuminate\Support\Facades\DB;
                return new class extends Migration {
                    public function up(): void {
                        $signature = 'public.fn_route_visibility_prepared(bigint,geometry,geometry,geometry,geometry)';
                        DB::statement("DROP FUNCTION $signature");
                        DB::statement("CREATE FUNCTION $signature RETURNS integer LANGUAGE sql AS 'SELECT 2'");
                        DB::statement("REVOKE EXECUTE ON FUNCTION $signature FROM PUBLIC");
                    }
                };
                PHP;
            File::put($directory.'/2099_01_01_000000_reset_helper_grants.php', $fixture);
            $this->artisan('migrate', ['--realpath' => true, '--path' => [$directory], '--force' => true])->assertSuccessful();
            foreach ($helpers as $helper) {
                $this->assertFalse(DB::selectOne("SELECT has_function_privilege('gp_graph_acl_test_reader', ?, 'EXECUTE') AS allowed", [$helper])->allowed);
            }
            DB::statement('SET LOCAL ROLE gp_queue_runtime');
            $this->assertEquals(2, DB::selectOne('SELECT public.fn_route_visibility_prepared(1,NULL,NULL,NULL,NULL) AS result')->result);
        } finally {
            DB::rollBack();
            File::deleteDirectory($directory);
        }
    }
}
