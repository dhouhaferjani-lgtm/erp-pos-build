<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for POS Order Management feature.
 *
 * Registers routes for the order lifecycle (create, lines, kitchen workflow, close).
 */
final class OrderServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Services are auto-resolved via constructor injection
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes_orders.php');
    }
}
