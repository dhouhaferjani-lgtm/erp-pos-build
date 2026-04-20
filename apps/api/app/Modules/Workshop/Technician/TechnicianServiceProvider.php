<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician;

use App\Modules\Workshop\Technician\Application\Contracts\TechnicianAvailabilityServiceInterface;
use App\Modules\Workshop\Technician\Application\Services\TechnicianAvailabilityService;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Workshop/Technician submodule.
 *
 * Binds Application-layer contracts to Infrastructure implementations. Repository bindings
 * are added in Task 10. Event listeners for WorkOrder lifecycle events (Plan B) are wired
 * via `App\Providers\EventServiceProvider::$listen` and are intentionally kept commented
 * out in this plan until Plan B lands the event classes.
 */
final class TechnicianServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            TechnicianAvailabilityServiceInterface::class,
            TechnicianAvailabilityService::class,
        );
    }

    public function boot(): void
    {
        // Routes + migrations are registered by Laravel's auto-discovery for `database/migrations`.
        // Module routes will be loaded once the Presentation layer lands (Task 12).
    }
}
