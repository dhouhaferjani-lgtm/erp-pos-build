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
 * @cross-tenant-by-design Per-seller fan-out queue job from the system-wide marketplace:delta-sync scheduler closure (MarketplaceServiceProvider::boot iterates MarketplaceSeller::active() globally and dispatches one SyncSellerListingsJob per seller every $intervalMinutes). The unscoped MarketplaceSeller::find($this->sellerId) lookup is gated by globally-unique seller UUID; downstream Product::where('company_id', $seller->company_id)->where('is_active', true)->where('is_physical', true) queries explicitly filter by seller.company_id. Same defense-in-depth seller-resolve hardening as ReconcileListingsJob — tracked at docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md (Finding B).
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
