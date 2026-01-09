<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for POS module.
 *
 * Registers routes and services for the POS system including:
 * - Shift management
 * - Cash drawer operations
 * - X and Z reports
 * - NF525 compliance (hash chains, grand totals)
 */
final class POSServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Services are auto-resolved via constructor injection
        // No explicit bindings needed for domain services
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }
}
