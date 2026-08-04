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
 * @cross-tenant-by-design Per-seller fan-out queue job. As of 2026-08-04 it is dispatched by MarketplaceReconcileCommand (marketplace:reconcile) from INSIDE an initialized tenant context — TenantScopedCommand::forEachTenant() opens the tenant, MarketplaceSeller::active() is read on that tenant's connection, and QueueTenancyBootstrapper stamps the tenant onto this job's payload; the worker re-initializes that tenant before handle() runs and reverts afterwards, so the job is tenant-SCOPED at runtime. The tag is retained (not BindsTenantContext) because the class carries no tenant id of its own and its queries are still written unscoped, in two distinct shapes: (1) MarketplaceSeller::find($this->sellerId) relies on the globally-unique seller UUID, and Product reads filter directly by seller->company_id; (2) MarketplaceListing reads/updates filter by seller_id ONLY, because MarketplaceListing has no company_id column (verified at apps/api/app/Modules/Marketplace/Domain/Models/MarketplaceListing.php:53-70) — isolation is anchored TRANSITIVELY through the seller_id FK to MarketplaceSeller (which carries company_id), and since each seller belongs to exactly one tenant, seller_id IS the per-tenant boundary for the listings table. Adopting BindsTenantContext (an explicit tenant id in the constructor, defence in depth against a payload dispatched from central context) plus any future MarketplaceListing migration adding company_id are tracked follow-ups — see docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md (Finding B) for api.marketplace.
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
