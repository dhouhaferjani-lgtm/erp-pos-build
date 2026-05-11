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
     * Tenant-isolation (api.marketplace.002): event payload MUST include
     * companyId so the Product + MarketplaceSeller lookups can be scoped
     * to the dispatching company's tenant boundary. A forged event whose
     * productId belongs to a foreign company cannot resolve.
     *
     * @param  object  $event  Event with productId and companyId properties
     */
    public function handle(object $event): void
    {
        if (! property_exists($event, 'productId') || ! property_exists($event, 'companyId')) {
            return;
        }

        /** @var Product|null $product */
        $product = Product::query()
            ->where('company_id', $event->companyId)
            ->find($event->productId);
        if ($product === null) {
            return;
        }

        $seller = MarketplaceSeller::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('company_id', $event->companyId)
            ->active()
            ->first();

        if ($seller === null) {
            return;
        }

        $this->listingSyncService->syncProduct($seller, $product);
    }
}
