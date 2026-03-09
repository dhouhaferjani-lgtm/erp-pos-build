<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Application\Listeners;

use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;

class SyncListingOnPriceChange
{
    public function __construct(
        private readonly ListingSyncService $listingSyncService,
    ) {}

    /**
     * Handle product update events.
     *
     * Syncs the listing when a product's price changes.
     *
     * @param  object  $event  Event with productId property
     */
    public function handle(object $event): void
    {
        if (! property_exists($event, 'productId')) {
            return;
        }

        /** @var Product|null $product */
        $product = Product::query()->find($event->productId);
        if ($product === null) {
            return;
        }

        $seller = MarketplaceSeller::where('company_id', $product->company_id)
            ->active()
            ->first();

        if ($seller === null) {
            return;
        }

        $this->listingSyncService->syncProduct($seller, $product);
    }
}
