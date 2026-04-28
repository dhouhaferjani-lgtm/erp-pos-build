<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Providers;

use App\Modules\Vehicle\Domain\Contracts\VehicleMileageRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleOwnershipRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Infrastructure\Persistence\EloquentVehicleMileageRepository;
use App\Modules\Vehicle\Infrastructure\Persistence\EloquentVehicleOwnershipRepository;
use App\Modules\Vehicle\Infrastructure\Persistence\EloquentVehicleRepository;
use Illuminate\Support\ServiceProvider;

class VehicleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            VehicleRepositoryInterface::class,
            EloquentVehicleRepository::class,
        );
        $this->app->bind(
            VehicleOwnershipRepositoryInterface::class,
            EloquentVehicleOwnershipRepository::class,
        );
        $this->app->bind(
            VehicleMileageRepositoryInterface::class,
            EloquentVehicleMileageRepository::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
