<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Listeners\InvoicePostedListener;
use App\Modules\Accounting\Listeners\PostGrIrOnGoodsReceipt;
use App\Modules\Accounting\Listeners\RefreshPartnerBalanceOnJournalEntryPosted;
use App\Modules\Company\Domain\Events\CompanyCreated;
use App\Modules\Company\Listeners\CreateFiscalYearsForNewCompany;
use App\Modules\Compliance\Listeners\EnsureFraudSettingsOnCompanyCreated;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Expense\Application\Listeners\CollectStatementExpenseSuggestions;
use App\Modules\Expense\Application\Listeners\SettleExpenseFromStatement;
use App\Modules\Expense\Application\Listeners\SyncExpenseOnInstrumentLifecycle;
use App\Modules\Import\Infrastructure\Listeners\BroadcastImportEventsListener;
use App\Modules\Inventory\Domain\Events\GoodsReceived;
use App\Modules\Loyalty\Application\Listeners\EarnPointsOnReceiptCompleted;
use App\Modules\Loyalty\Application\Listeners\SeedDefaultEarningRuleOnProgramActivated;
use App\Modules\Loyalty\Domain\Events\ProgramActivated;
use App\Modules\Partner\Domain\Events\PartnerDeleted;
use App\Modules\Partner\Infrastructure\Listeners\BroadcastPartnerEventsListener;
use App\Modules\POS\Domain\Events\ReceiptCompleted;
use App\Modules\POS\Domain\Events\ReceiptVoided;
use App\Modules\POS\Infrastructure\Listeners\BroadcastPosEventsListener;
use App\Modules\Progression\Infrastructure\Listeners\RegisterCompanyWithGrowthAdvisor;
use App\Modules\Scheduling\Infrastructure\Listeners\MirrorAppointmentOnWorkOrderCancelled;
use App\Modules\Scheduling\Infrastructure\Listeners\MirrorAppointmentOnWorkOrderClosed;
use App\Modules\Scheduling\Infrastructure\Listeners\MirrorAppointmentOnWorkOrderCompleted;
use App\Modules\Scheduling\Infrastructure\Listeners\MirrorAppointmentOnWorkOrderStarted;
use App\Modules\Treasury\Domain\Events\ExpenseSettlementRequestedFromStatement;
use App\Modules\Treasury\Domain\Events\ExpenseStatementSuggestionsRequested;
use App\Modules\Treasury\Domain\Events\InstrumentCancelled;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Vehicle\Domain\Events\VehicleOwnerChanged;
use App\Modules\Vehicle\Infrastructure\Listeners\CloseOwnershipsOnPartnerDeleted;
use App\Modules\Vehicle\Infrastructure\Listeners\RecordVehicleOwnerChangedAuditEvent;
use App\Modules\Vehicle\Infrastructure\Listeners\WriteMileageReadingFromWorkOrderCompleted;
use App\Modules\Voucher\Infrastructure\Listeners\VoucherCascadeOnReceiptVoidedListener;
use App\Modules\Workshop\Technician\Application\Listeners\ReopenTimeEntryOnWorkOrderResumed;
use App\Modules\Workshop\Technician\Infrastructure\Listeners\CloseTimeEntryOnWorkOrderCompleted;
use App\Modules\Workshop\Technician\Infrastructure\Listeners\CloseTimeEntryOnWorkOrderPaused;
use App\Modules\Workshop\Technician\Infrastructure\Listeners\CreateTimeEntryOnWorkOrderStarted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCancelled;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderClosed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompletedV2;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderPartsNeeded;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderPaused;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderResumed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderStarted;
use App\Modules\Workshop\WorkOrder\Infrastructure\Listeners\LogPartsNeededForProcurement;
use App\Modules\Workshop\WorkOrder\Infrastructure\Listeners\WriteDocumentVehicleContextForWorkOrderInvoice;
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
            EnsureFraudSettingsOnCompanyCreated::class,
        ],
        JournalEntryPosted::class => [
            RefreshPartnerBalanceOnJournalEntryPosted::class,
        ],
        InstrumentCleared::class => [
            SyncExpenseOnInstrumentLifecycle::class,
        ],
        InstrumentCancelled::class => [
            SyncExpenseOnInstrumentLifecycle::class,
        ],
        ExpenseSettlementRequestedFromStatement::class => [
            SettleExpenseFromStatement::class,
        ],
        ExpenseStatementSuggestionsRequested::class => [
            CollectStatementExpenseSuggestions::class,
        ],
        InvoicePosted::class => [
            InvoicePostedListener::class,
            WriteDocumentVehicleContextForWorkOrderInvoice::class,
        ],
        ProgramActivated::class => [
            SeedDefaultEarningRuleOnProgramActivated::class,
        ],
        ReceiptCompleted::class => [
            EarnPointsOnReceiptCompleted::class,
        ],
        ReceiptVoided::class => [
            VoucherCascadeOnReceiptVoidedListener::class,
        ],
        PartnerDeleted::class => [
            CloseOwnershipsOnPartnerDeleted::class,
        ],
        VehicleOwnerChanged::class => [
            RecordVehicleOwnerChangedAuditEvent::class,
        ],

        // ----------------------------------------------------------------------
        // Workshop/Technician + Vehicle mileage listeners subscribing to the
        // Plan B (WorkOrder) lifecycle events. Canonical event signatures live
        // under \App\Modules\Workshop\WorkOrder\Domain\Events\*.
        // ----------------------------------------------------------------------
        WorkOrderStarted::class => [
            CreateTimeEntryOnWorkOrderStarted::class,
            MirrorAppointmentOnWorkOrderStarted::class,
        ],
        WorkOrderPaused::class => [
            CloseTimeEntryOnWorkOrderPaused::class,
        ],
        WorkOrderResumed::class => [
            ReopenTimeEntryOnWorkOrderResumed::class,
        ],
        WorkOrderCompletedV2::class => [
            CloseTimeEntryOnWorkOrderCompleted::class,
            WriteMileageReadingFromWorkOrderCompleted::class,
            MirrorAppointmentOnWorkOrderCompleted::class,
        ],
        WorkOrderCancelled::class => [
            MirrorAppointmentOnWorkOrderCancelled::class,
        ],
        WorkOrderClosed::class => [
            MirrorAppointmentOnWorkOrderClosed::class,
        ],
        WorkOrderPartsNeeded::class => [
            LogPartsNeededForProcurement::class,
        ],

        // Procurement-to-Pay: GR-IR accrual on goods receipt
        GoodsReceived::class => [
            PostGrIrOnGoodsReceipt::class,
        ],
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
