<?php

namespace App\Providers;

use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Repositories\Admin\EloquentSignedUserRepository;
use App\Repositories\Admin\SignedUserRepositoryInterface;
use App\Repositories\Admin\AdminRepositoryInterface;
use App\Repositories\Admin\EloquentAdminRepository;
use App\Services\Admin\DashboardService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->mapApiRoutes();
        $this->mapLocationShareRoutes();
        $this->mapRouteHistoryRoutes();

        // Laravel fires this before committing each transactional migration (up or down).
        Event::listen(MigrationEnded::class, static function (MigrationEnded $event): void {
            $connection = DB::connection($event->migration->getConnection());
            if ($connection->getDriverName() === 'pgsql') {
                $connection->unprepared(File::get(database_path('sql/20261121_210000_queue_prepared_graph_geometry.up.sql')));
            }
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Admin repositories
        $this->app->bind(SignedUserRepositoryInterface::class, EloquentSignedUserRepository::class);
        $this->app->bind(AdminRepositoryInterface::class, EloquentAdminRepository::class);
        $this->app->singleton(DashboardService::class, fn() => new DashboardService());
    }

    /**
     * Define the main API routes.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')
             ->middleware(['api', \App\Http\Middleware\CaptureRouteHistory::class])
             ->namespace('App\\Http\\Controllers\\Api')
             ->group(base_path('routes/api.php'));
    }

    /**
     * Keep location-sharing routes isolated while reusing the public user JWT middleware.
     */
    protected function mapLocationShareRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', \App\Http\Middleware\UserAuth::class])
            ->group(base_path('routes/location_shares.php'));
    }

    /**
     * Route history is private to the authenticated end user.
     */
    protected function mapRouteHistoryRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', \App\Http\Middleware\UserAuth::class])
            ->group(base_path('routes/route_history.php'));
    }
}
