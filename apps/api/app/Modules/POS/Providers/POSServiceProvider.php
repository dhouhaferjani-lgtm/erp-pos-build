<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Commands\VerifyPosChainCommand;
use App\Shared\Contracts\Compliance\Nf525DataProviderContract;
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
        // Services are auto-resolved via constructor injection.
        // Cross-module contracts published by POS are bound explicitly:
        $this->app->bind(
            Nf525DataProviderContract::class,
            Nf525DataProvider::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyPosChainCommand::class,
            ]);
        }

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }
}
