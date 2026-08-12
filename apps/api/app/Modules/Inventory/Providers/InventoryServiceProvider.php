<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Application\Contracts\InventoryReservationServiceInterface;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Application\Listeners\ExitOnboardingOnFullCountFinalized;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBoundaryGuard;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Application\Services\InventoryGlPostingService;
use App\Modules\Inventory\Application\Services\LinkedCostApplicationService;
use App\Modules\Inventory\Application\Services\LocationStockQueryService;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Application\Services\TransferLineQueryService;
use App\Modules\Inventory\Application\Services\VariantStockReaderService;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Infrastructure\Commands\ExpireStockReservationsCommand;
use App\Shared\Contracts\Inventory\LinkedCostApplicatorInterface;
use App\Shared\Contracts\Inventory\ReceiptLineGuardInterface;
use App\Shared\Contracts\Inventory\ReservationReleaserInterface;
use App\Shared\Contracts\LocationStockReader;
use App\Shared\Contracts\TransferLineReader;
use App\Shared\Contracts\VariantStockReader;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(InventoryGlPostingBuffer::class, function (): InventoryGlPostingBuffer {
            return new InventoryGlPostingBuffer(
                $this->app->make(InventoryGlPostingService::class),
                $this->app->runningUnitTests(),
            );
        });

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
        $this->registerTestBoundaryGuard();

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
        // Apply stock adjustments when inventory counting is completed
        Event::listen(InventoryCountingCompleted::class, ApplyStockAdjustmentsOnCountingCompleted::class);

        // Onboarding auto-exit (C3): a finalized whole-location count sourced
        // from the full catalog (includes_zero_stock) exits onboarding_mode.
        Event::listen(InventoryCountingCompleted::class, ExitOnboardingOnFullCountFinalized::class);

        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            if ($event->connectionName !== DB::getDefaultConnection()) {
                return;
            }

            if ($event->connection->transactionLevel() !== 0) {
                return;
            }

            if (! $this->app->resolved(InventoryGlPostingBuffer::class)) {
                return;
            }

            $buffer = $this->app->make(InventoryGlPostingBuffer::class);
            if (! $buffer->isEmpty()) {
                Log::warning('Discarding inventory GL contexts after root transaction rollback.');
            }
            $buffer->reset();
        });
    }

    private function registerTestBoundaryGuard(): void
    {
        if (! $this->app->runningUnitTests()) {
            return;
        }

        Event::listen(RequestHandled::class, function (): void {
            $this->app->make(InventoryGlPostingBoundaryGuard::class)->assertEmpty('request');
        });
        Event::listen(JobProcessed::class, function (): void {
            $this->app->make(InventoryGlPostingBoundaryGuard::class)->assertEmpty('job-success');
        });
        Event::listen(JobExceptionOccurred::class, function (): void {
            $this->app->make(InventoryGlPostingBoundaryGuard::class)->assertEmpty('job-failure');
        });
    }
}
