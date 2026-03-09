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
