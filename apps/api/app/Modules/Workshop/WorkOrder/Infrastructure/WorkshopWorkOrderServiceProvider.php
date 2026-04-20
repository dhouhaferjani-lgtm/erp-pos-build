<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure;

use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderLineRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderSequenceInterface;
use App\Modules\Workshop\WorkOrder\Infrastructure\Persistence\EloquentWorkOrderLineRepository;
use App\Modules\Workshop\WorkOrder\Infrastructure\Persistence\EloquentWorkOrderRepository;
use App\Modules\Workshop\WorkOrder\Infrastructure\Persistence\EloquentWorkOrderSequence;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Workshop/WorkOrder submodule.
 *
 * Binds Domain-layer repository contracts to Eloquent implementations.
 * Transition-service + application-service bindings land in later tasks
 * (10-14). Routes (Task 17) are loaded in `boot()`.
 */
final class WorkshopWorkOrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
    }
}
