<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Infrastructure\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Marketplace\Infrastructure\Commands\MarketplaceDeltaSyncCommand;
use App\Modules\Product\Domain\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Per-seller delta listing sync, fanned out by
 * {@see MarketplaceDeltaSyncCommand} (`marketplace:delta-sync`) once per
 * ACTIVE marketplace seller inside each tenant's context.
 *
 * Tenant-isolation: the job carries the dispatching tenant on its payload and
 * rebinds it through {@see BindsTenantContext::withTenantContext()} before any
 * data access (Finding B closure, 2026-08-05 — this class previously carried a
 * `@cross-tenant-by-design` tag instead). QueueTenancyBootstrapper already
 * stamps the tenant at dispatch time, but that stamp only holds for a payload
 * that was created under initialized tenancy and reached the worker intact; a
 * `queue:retry` of a `failed_jobs` row, a manual re-queue, or a synchronous
 * dispatch from a console context would otherwise run the seller lookup against
 * the CENTRAL database. The explicit tenant id makes the binding
 * self-sufficient, and the trait's fail-loud null handling turns a
 * deleted/never-existed tenant into a visible job failure instead of a silent
 * no-op.
 *
 * DELIBERATELY NOT tenant_id-scoped: `MarketplaceSeller::find($this->sellerId)`
 * carries no `where('tenant_id', $this->tenantId)` predicate, and the
 * downstream Product reads filter by `$seller->company_id`. The audit's
 * Finding B recommended adding that predicate; it must NOT be added, because
 * `marketplace_sellers.tenant_id` is NULLABLE — external / Synerivia-owned
 * sellers carry NULL (MarketplaceSellerFactory::external()) and the predicate
 * would silently drop exactly those rows. Under database-per-tenant the seller
 * table is physically isolated inside the bound tenant database, so the bind —
 * not a WHERE clause — is the isolation boundary. Pinned by
 * tests/Feature/Marketplace/MarketplaceListingJobsTenantContextTest.php.
 */
class SyncSellerListingsJob implements ShouldQueue
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
            // this job). Discard rather than guess a tenant: marketplace:delta-sync
            // re-fans-out every active seller on its next tick, so nothing is lost.
            Log::warning('SyncSellerListingsJob discarded: queue payload carries no tenant anchor.', [
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

            // Delta sync: query products updated since last sync
            $query = Product::where('company_id', $seller->company_id)
                ->where('is_active', true)
                ->where('is_physical', true);

            if ($seller->last_sync_at !== null) {
                $query->where('updated_at', '>', $seller->last_sync_at);
            }

            $query->chunk(100, function ($products) use ($seller, $listingSyncService): void {
                foreach ($products as $product) {
                    $listingSyncService->syncProduct($seller, $product);
                }
            });

            $seller->update(['last_sync_at' => now()]);
        });
    }
}
