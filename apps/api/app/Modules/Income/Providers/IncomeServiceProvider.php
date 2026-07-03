<?php

declare(strict_types=1);

namespace App\Modules\Income\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Income module.
 */
class IncomeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
