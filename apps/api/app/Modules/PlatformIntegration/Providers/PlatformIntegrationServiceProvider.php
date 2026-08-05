<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Providers;

use App\Modules\PlatformIntegration\Application\Commands\CheckPendingEnrichmentsCommand;
use App\Modules\PlatformIntegration\Application\Contracts\PlatformVehicleQueryInterface;
use App\Modules\PlatformIntegration\Infrastructure\Platform\EloquentPlatformVehicleQuery;
use Illuminate\Support\ServiceProvider;

class PlatformIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PlatformVehicleQueryInterface::class,
            EloquentPlatformVehicleQuery::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        // `enrichment:check-pending` was NEVER a registered Artisan command
        // (2026-08-05 cat-(b) conversion, finding beyond the audit). Laravel's
        // auto-discovery only walks app/Console/Commands, and this class lives
        // under app/Modules/PlatformIntegration/Application/Commands, so
        // `php artisan list` never showed it and the scheduler's
        // `Schedule::command('enrichment:check-pending')` entry — which is just
        // a command STRING, never validated at registration time — shelled out
        // to a command that does not exist on every 15-minute tick. That entry
        // also carried no ->onFailure(), so the failure was silent. It had to
        // be registered before the cross-tenant conversion could matter.
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckPendingEnrichmentsCommand::class,
            ]);
        }
    }
}
