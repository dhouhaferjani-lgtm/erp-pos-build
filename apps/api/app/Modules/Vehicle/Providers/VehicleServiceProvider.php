<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Providers;

use App\Modules\Vehicle\Application\Services\VehiclePartnerReferenceSource;
use App\Modules\Vehicle\Domain\Contracts\VehicleMileageRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleOwnershipRepositoryInterface;
use App\Modules\Vehicle\Domain\Contracts\VehicleRepositoryInterface;
use App\Modules\Vehicle\Infrastructure\Persistence\EloquentVehicleMileageRepository;
use App\Modules\Vehicle\Infrastructure\Persistence\EloquentVehicleOwnershipRepository;
use App\Modules\Vehicle\Infrastructure\Persistence\EloquentVehicleRepository;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Support\ServiceProvider;

class VehicleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([VehiclePartnerReferenceSource::class], PartnerReferenceSource::class);

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
