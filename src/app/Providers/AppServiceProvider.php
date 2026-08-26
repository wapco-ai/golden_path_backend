<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LanguageController;
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
        $this->mapApiRoutes(); // تعریف مسیرهای API
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
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')
             ->middleware('api')
             ->namespace('App\Http\Controllers\Api') // اطمینان از فضای نام صحیح
             ->group(base_path('routes/api.php'));
    }
}
