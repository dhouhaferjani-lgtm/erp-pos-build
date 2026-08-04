<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Infrastructure\Jobs;

use App\Modules\Marketplace\Application\Services\ListingSyncService;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * @cross-tenant-by-design Per-seller fan-out queue job. As of 2026-08-04 it is dispatched by MarketplaceDeltaSyncCommand (marketplace:delta-sync) from INSIDE an initialized tenant context — TenantScopedCommand::forEachTenant() opens the tenant, MarketplaceSeller::active() is read on that tenant's connection, and QueueTenancyBootstrapper stamps the tenant onto this job's payload; the worker re-initializes that tenant before handle() runs and reverts afterwards, so the job is tenant-SCOPED at runtime. The tag is retained (not BindsTenantContext) because the class carries no tenant id of its own and its queries are still written unscoped: MarketplaceSeller::find($this->sellerId) relies on the globally-unique seller UUID, and the downstream Product::where('company_id', $seller->company_id)->where('is_active', true)->where('is_physical', true) reads filter by seller.company_id. Adopting BindsTenantContext (an explicit tenant id in the constructor, defence in depth against a payload dispatched from central context) is a tracked follow-up — see docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md (Finding B).
 */
class SyncSellerListingsJob implements ShouldQueue
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
    }
}
