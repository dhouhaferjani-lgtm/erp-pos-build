<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Infrastructure\Providers;

use App\Modules\Tenant\Application\Commands\BackupTenantCommand;
use App\Modules\Tenant\Application\Commands\CreateTenantCommand;
use App\Modules\Tenant\Application\Commands\DeprovisionTenantCommand;
use App\Modules\Tenant\Application\Commands\ReconcileIdentitiesCommand;
use App\Modules\Tenant\Application\Commands\ReconcileModulesCommand;
use App\Modules\Tenant\Application\Commands\ResetTenantCommand;
use App\Modules\Tenant\Application\Commands\RestoreTenantCommand;
use App\Modules\Tenant\Application\Commands\RollingTenantMigrationCommand;
use App\Modules\Tenant\Application\Commands\TenantStatusCommand;
use App\Modules\Tenant\Application\Contracts\ExecutionTimeLimit;
use App\Modules\Tenant\Infrastructure\Runtime\PhpExecutionTimeLimit;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ExecutionTimeLimit::class, PhpExecutionTimeLimit::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupTenantCommand::class,
                CreateTenantCommand::class,
                DeprovisionTenantCommand::class,
                ReconcileIdentitiesCommand::class,
                ReconcileModulesCommand::class,
                ResetTenantCommand::class,
                RestoreTenantCommand::class,
                RollingTenantMigrationCommand::class,
                TenantStatusCommand::class,
            ]);
        }
    }
}
