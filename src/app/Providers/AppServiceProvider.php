<?php

namespace App\Providers;

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
             ->middleware('api')
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
}
