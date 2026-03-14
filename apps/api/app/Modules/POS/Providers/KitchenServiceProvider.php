<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for POS Kitchen Display System feature.
 *
 * Registers routes for the KDS API.
 */
final class KitchenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Services are auto-resolved via constructor injection
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes_kitchen.php');
    }
}
