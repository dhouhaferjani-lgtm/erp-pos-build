<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Company\Services\CompanyContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register CompanyContext as a singleton so it maintains state across the request
        $this->app->singleton(CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure rate limiters for the application.
     */
    private function configureRateLimiting(): void
    {
        // Rate limiter for admin login (strict - 5 attempts per minute per IP)
        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip() ?? 'unknown');
        });

        // Rate limiter for admin sensitive operations (30 per minute per user)
        RateLimiter::for('admin-sensitive', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? $user->getAuthIdentifier() : ($request->ip() ?? 'unknown');

            return Limit::perMinute(30)->by((string) $key);
        });
    }
}
