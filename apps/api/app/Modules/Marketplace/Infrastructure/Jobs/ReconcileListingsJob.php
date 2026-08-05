<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Infrastructure\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Enums\ListingStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Marketplace\Infrastructure\Commands\MarketplaceReconcileCommand;
use App\Modules\Product\Domain\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Per-seller full listing reconciliation, fanned out by
 * {@see MarketplaceReconcileCommand} (`marketplace:reconcile`) once per ACTIVE
 * marketplace seller inside each tenant's context.
 *
 * Tenant-isolation: the job carries the dispatching tenant on its payload and
 * rebinds it through {@see BindsTenantContext::withTenantContext()} before any
 * data access (Finding B closure, 2026-08-05 — this class previously carried a
 * `@cross-tenant-by-design` tag instead). QueueTenancyBootstrapper already
 * stamps the tenant at dispatch time, but that stamp only holds for a payload
 * created under initialized tenancy that reached the worker intact; a
 * `queue:retry` of a `failed_jobs` row, a manual re-queue, or a synchronous
 * dispatch from a console context would otherwise run against the CENTRAL
 * database. The explicit tenant id makes the binding self-sufficient, and the
 * trait's fail-loud null handling turns a deleted/never-existed tenant into a
 * visible job failure instead of a silent no-op.
 *
 * DELIBERATELY NOT tenant_id-scoped, in two distinct shapes:
 *   (1) `MarketplaceSeller::find($this->sellerId)` carries no
 *       `where('tenant_id', $this->tenantId)` predicate — the audit's Finding B
 *       recommended one, and it must NOT be added:
 *       `marketplace_sellers.tenant_id` is NULLABLE and external /
 *       Synerivia-owned sellers carry NULL
 *       (MarketplaceSellerFactory::external()), so the predicate would silently
 *       drop exactly those rows. Product reads filter by
 *       `$seller->company_id`.
 *   (2) MarketplaceListing reads/updates filter by `seller_id` ONLY, because
 *       MarketplaceListing has no `company_id` column (verified at
 *       apps/api/app/Modules/Marketplace/Domain/Models/MarketplaceListing.php:53-70)
 *       — isolation is anchored TRANSITIVELY through the `seller_id` FK, and
 *       since each seller belongs to exactly one tenant database, `seller_id`
 *       IS the per-tenant boundary for the listings table. A future migration
 *       adding `company_id` to MarketplaceListing remains a tracked follow-up.
 *
 * Under database-per-tenant both tables are physically isolated inside the
 * bound tenant database, so the bind — not a WHERE clause — is the isolation
 * boundary. Pinned by
 * tests/Feature/Marketplace/MarketplaceListingJobsTenantContextTest.php.
 */
class ReconcileListingsJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Declared (NOT promoted) and nullable ON PURPOSE. A queue payload
     * serialized BEFORE the tenant anchor existed carries no `tenantId` key at
     * all, and `SerializesModels::__unserialize()` skips absent keys — a
     * promoted `readonly string` would stay uninitialized and every read of it
     * would fatal with "Typed property must not be accessed before
     * initialization", making pre-existing `failed_jobs` rows permanently
     * un-retryable. A declared property with a default unserializes to that
     * default instead, and `__serialize()` omits default-valued properties, so
     * new payloads do not grow. New dispatches always supply it (see the
     * constructor); legacy payloads are discarded in {@see self::handle()}.
     */
    public ?string $tenantId = null;

    public function __construct(
        public readonly string $sellerId,
        string $tenantId,
    ) {
        $this->tenantId = $tenantId;
    }

    public function handle(ListingSyncService $listingSyncService): void
    {
        if ($this->tenantId === null) {
            // Pre-anchor payload (dispatched before the tenant id existed on
            // this job). Discard rather than guess a tenant: marketplace:reconcile
            // re-fans-out every active seller on its next tick, so nothing is lost.
            Log::warning('ReconcileListingsJob discarded: queue payload carries no tenant anchor.', [
                'job' => static::class,
                'seller_id' => $this->sellerId,
            ]);

            return;
        }

        $this->withTenantContext(function () use ($listingSyncService): void {
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
        });
    }
}
