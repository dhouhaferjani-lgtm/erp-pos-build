<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure;

use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderAssignmentService;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderAuthoringService;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderBundleService;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderCreationService;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderLineService;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkshopPartnerReferenceSource;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderCreationServiceInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderLineRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderSequenceInterface;
use App\Modules\Workshop\WorkOrder\Infrastructure\Persistence\EloquentWorkOrderLineRepository;
use App\Modules\Workshop\WorkOrder\Infrastructure\Persistence\EloquentWorkOrderRepository;
use App\Modules\Workshop\WorkOrder\Infrastructure\Persistence\EloquentWorkOrderSequence;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Workshop/WorkOrder submodule.
 *
 * Binds Domain-layer repository contracts to Eloquent implementations and
 * wires application services. Routes (Task 15) are loaded in `boot()`.
 */
final class WorkshopWorkOrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([WorkshopPartnerReferenceSource::class], PartnerReferenceSource::class);

        $this->app->bind(
            WorkOrderRepositoryInterface::class,
            EloquentWorkOrderRepository::class,
        );
        $this->app->bind(
            WorkOrderLineRepositoryInterface::class,
            EloquentWorkOrderLineRepository::class,
        );
        $this->app->bind(
            WorkOrderSequenceInterface::class,
            EloquentWorkOrderSequence::class,
        );

        $this->app->singleton(WorkOrderAuthoringService::class);
        $this->app->singleton(WorkOrderLineService::class);
        $this->app->singleton(WorkOrderBundleService::class);
        $this->app->singleton(WorkOrderAssignmentService::class);
        $this->app->singleton(WorkOrderTransitionService::class);
        $this->app->bind(
            WorkOrderCreationServiceInterface::class,
            WorkOrderCreationService::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
