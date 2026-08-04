<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Inventory\Application\Contracts\InventoryReservationServiceInterface;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Application\Listeners\ExitOnboardingOnFullCountFinalized;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Application\Services\LinkedCostApplicationService;
use App\Modules\Inventory\Application\Services\LocationStockQueryService;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Application\Services\TransferLineQueryService;
use App\Modules\Inventory\Application\Services\VariantStockReaderService;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Infrastructure\Commands\ExpireStockReservationsCommand;
use App\Modules\Inventory\Listeners\PostCOGSOnInvoice;
use App\Shared\Contracts\Inventory\LinkedCostApplicatorInterface;
use App\Shared\Contracts\Inventory\ReceiptLineGuardInterface;
use App\Shared\Contracts\Inventory\ReservationReleaserInterface;
use App\Shared\Contracts\LocationStockReader;
use App\Shared\Contracts\TransferLineReader;
use App\Shared\Contracts\VariantStockReader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ReceiptLineGuardInterface::class,
            GoodsReceiptService::class,
        );
        $this->app->bind(
            ReservationReleaserInterface::class,
            StockReservationService::class,
        );

        $this->app->bind(
            InventoryReservationServiceInterface::class,
            StockReservationService::class,
        );

        // POS location stock feed read model (spec §4.1) — implemented by
        // Inventory, consumed cross-module via the Shared contract.
        $this->app->bind(LocationStockReader::class, LocationStockQueryService::class);

        // On-hand variant stock read model — implemented by Inventory,
        // consumed cross-module by Catalog's variant delete guard (D1).
        $this->app->bind(VariantStockReader::class, VariantStockReaderService::class);
        $this->app->bind(TransferLineReader::class, TransferLineQueryService::class);

        $this->app->bind(LinkedCostApplicatorInterface::class, LinkedCostApplicationService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
        $this->registerEventListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireStockReservationsCommand::class,
            ]);
        }
    }

    /**
     * Register event listeners for inventory-related events.
     */
    private function registerEventListeners(): void
    {
        // Post COGS when an invoice is posted
        Event::listen(InvoicePosted::class, PostCOGSOnInvoice::class);

        // Apply stock adjustments when inventory counting is completed
        Event::listen(InventoryCountingCompleted::class, ApplyStockAdjustmentsOnCountingCompleted::class);

        // Onboarding auto-exit (C3): a finalized whole-location count sourced
        // from the full catalog (includes_zero_stock) exits onboarding_mode.
        Event::listen(InventoryCountingCompleted::class, ExitOnboardingOnFullCountFinalized::class);
    }
}
