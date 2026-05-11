<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Infrastructure\Jobs;

use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Enums\ListingStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * @cross-tenant-by-design Per-seller fan-out queue job from the system-wide marketplace:reconcile scheduler closure (MarketplaceServiceProvider:38-42 iterates MarketplaceSeller::active() globally and dispatches one ReconcileListingsJob per seller). The unscoped MarketplaceSeller::find($this->sellerId) lookup is gated by globally-unique seller UUID. Two distinct downstream tenant-scope shapes apply: (1) Product reads explicitly filter by seller->company_id (lines 39-49) — Product carries company_id and the WHERE predicate is direct; (2) MarketplaceListing reads/updates filter by seller_id only (lines 54-58) because MarketplaceListing has no company_id column (verified at apps/api/app/Modules/Marketplace/Domain/Models/MarketplaceListing.php:53-70) — tenant isolation is anchored TRANSITIVELY through the seller_id FK to MarketplaceSeller (which carries company_id). Each MarketplaceSeller is exclusively per-tenant, so seller_id IS the per-tenant boundary for the listings table. Defense-in-depth seller-resolve hardening (re-asserting tenant on the MarketplaceSeller lookup itself, and any future MarketplaceListing schema migration that adds company_id) is tracked separately at docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md (Finding B) for api.marketplace.
 */
class ReconcileListingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $sellerId,
    ) {}

    public function handle(ListingSyncService $listingSyncService): void
    {
        $seller = MarketplaceSeller::find($this->sellerId);
        if ($seller === null || $seller->company_id === null) {
            return;
        }

        // Full reconciliation: sync all active products
        Product::where('company_id', $seller->company_id)
            ->chunk(100, function ($products) use ($seller, $listingSyncService): void {
                foreach ($products as $product) {
                    $listingSyncService->syncProduct($seller, $product);
                }
            });

        // Delist listings whose source products no longer exist or are inactive
        $activeProductIds = Product::where('company_id', $seller->company_id)
            ->where('is_active', true)
            ->where('is_physical', true)
            ->whereNotNull('sale_price')
            ->pluck('id');

        MarketplaceListing::where('seller_id', $seller->id)
            ->whereNotNull('source_product_id')
            ->whereNotIn('source_product_id', $activeProductIds)
            ->where('listing_status', '!=', ListingStatus::Delisted)
            ->update(['listing_status' => ListingStatus::Delisted]);

        $seller->update(['last_sync_at' => now()]);
    }
}
