<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for POS Table Management feature.
 *
 * Registers routes for floor and table CRUD operations.
 */
final class TableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Services are auto-resolved via constructor injection
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes_tables.php');
    }
}
