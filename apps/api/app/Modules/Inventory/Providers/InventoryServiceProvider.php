<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Listeners\PostCOGSOnInvoice;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
        $this->registerEventListeners();
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
    }
}
