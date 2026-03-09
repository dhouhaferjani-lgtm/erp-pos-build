<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\Listeners;

use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;

class SyncListingOnStockChange
{
    public function __construct(
        private readonly ListingSyncService $listingSyncService,
    ) {}

    /**
     * Handle stock level change events.
     *
     * Looks up the seller for the product's company and syncs the listing.
     *
     * @param  object  $event  Event with productId and companyId properties
     */
    public function handle(object $event): void
    {
        if (! property_exists($event, 'productId') || ! property_exists($event, 'companyId')) {
            return;
        }

        /** @var Product|null $product */
        $product = Product::query()->find($event->productId);
        if ($product === null) {
            return;
        }

        $seller = MarketplaceSeller::where('company_id', $event->companyId)
            ->active()
            ->first();

        if ($seller === null) {
            return;
        }

        $this->listingSyncService->syncProduct($seller, $product);
    }
}
