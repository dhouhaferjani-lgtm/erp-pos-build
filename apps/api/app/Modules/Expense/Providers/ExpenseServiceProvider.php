<?php

declare(strict_types=1);

namespace App\Modules\Expense\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Expense module.
 */
class ExpenseServiceProvider extends ServiceProvider
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
