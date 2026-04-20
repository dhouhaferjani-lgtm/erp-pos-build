<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Providers;

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
    }
}
