<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Accounting\Listeners\InvoicePostedListener;
use App\Modules\Company\Domain\Events\CompanyCreated;
use App\Modules\Company\Listeners\CreateFiscalYearsForNewCompany;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Import\Infrastructure\Listeners\BroadcastImportEventsListener;
use App\Modules\Loyalty\Application\Listeners\EarnPointsOnReceiptCompleted;
use App\Modules\Partner\Domain\Events\PartnerDeleted;
use App\Modules\Partner\Infrastructure\Listeners\BroadcastPartnerEventsListener;
use App\Modules\POS\Domain\Events\ReceiptCompleted;
use App\Modules\POS\Infrastructure\Listeners\BroadcastPosEventsListener;
use App\Modules\Progression\Infrastructure\Listeners\RegisterCompanyWithGrowthAdvisor;
use App\Modules\Vehicle\Domain\Events\VehicleOwnerChanged;
use App\Modules\Vehicle\Infrastructure\Listeners\CloseOwnershipsOnPartnerDeleted;
use App\Modules\Vehicle\Infrastructure\Listeners\RecordVehicleOwnerChangedAuditEvent;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        CompanyCreated::class => [
            CreateFiscalYearsForNewCompany::class,
            RegisterCompanyWithGrowthAdvisor::class,
        ],
        InvoicePosted::class => [
            InvoicePostedListener::class,
        ],
        ReceiptCompleted::class => [
            EarnPointsOnReceiptCompleted::class,
        ],
        PartnerDeleted::class => [
            CloseOwnershipsOnPartnerDeleted::class,
        ],
        VehicleOwnerChanged::class => [
            RecordVehicleOwnerChangedAuditEvent::class,
        ],
        // After Plan B (Workshop WorkOrderCompleted) merges, add:
        //   \App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompleted::class => [
        //       \App\Modules\Vehicle\Infrastructure\Listeners\WriteMileageReadingFromWorkOrderCompleted::class,
        //   ],
        // (stub listener already exists at the path above)
    ];

    /**
     * The subscriber classes to register.
     *
     * @var array<int, class-string>
     */
    protected $subscribe = [
        BroadcastImportEventsListener::class,
        BroadcastPartnerEventsListener::class,
        BroadcastPosEventsListener::class,
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
    }
}
