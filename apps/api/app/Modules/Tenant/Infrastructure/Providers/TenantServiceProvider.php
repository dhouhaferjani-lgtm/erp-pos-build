<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Infrastructure\Providers;

use App\Modules\Tenant\Application\Commands\CreateTenantCommand;
use App\Modules\Tenant\Application\Commands\DeprovisionTenantCommand;
use App\Modules\Tenant\Application\Commands\ReconcileIdentitiesCommand;
use App\Modules\Tenant\Application\Commands\ResetTenantCommand;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
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
        $this->loadRoutesFrom(__DIR__.'/../../routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateTenantCommand::class,
                DeprovisionTenantCommand::class,
                ReconcileIdentitiesCommand::class,
                ResetTenantCommand::class,
            ]);
        }
    }
}
