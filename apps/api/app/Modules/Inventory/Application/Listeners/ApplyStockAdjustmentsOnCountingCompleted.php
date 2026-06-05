<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class ApplyStockAdjustmentsOnCountingCompleted implements ShouldQueue
{
    /**
     * The number of times the queued listener may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 10;

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
    ) {}

    public function handle(InventoryCountingCompleted $event): void
    {
        $counting = InventoryCounting::with('items')->find($event->countingId);

        if ($counting === null) {
            Log::error('ApplyStockAdjustments: Counting not found', [
                'counting_id' => $event->countingId,
            ]);

            return;
        }

        $reference = 'COUNTING:'.($counting->counting_number ?? $counting->id);
        $adjustedCount = 0;

        foreach ($counting->items as $item) {
            if ($item->resolution_method === ItemResolutionMethod::Pending) {
                Log::warning('ApplyStockAdjustments: Skipping pending item in finalized counting', [
                    'counting_id' => $event->countingId,
                    'item_id' => $item->id,
                ]);

                continue;
            }

            $finalQty = $item->final_qty;
            $theoreticalQty = $item->theoretical_qty;

            if ($finalQty === null || $finalQty === $theoreticalQty) {
                continue;
            }

            // Compute delta from counting, then apply to current stock
            // This preserves stock movements that occurred during the counting period
            $delta = bcsub($finalQty, $theoreticalQty, 4);

            // Scope the current-stock lookup to the variant row when the item
            // was counted against a specific variant (Task 20).  Without this
            // scope the query would land on the product-level row (variant_id IS
            // NULL) even when the item carries a variant, and the adjustment
            // would target the wrong stock bucket.
            $currentStock = StockLevel::where('product_id', $item->product_id)
                ->where('location_id', $item->location_id)
                ->when(
                    $item->variant_id !== null,
                    fn ($q) => $q->where('variant_id', $item->variant_id),
                    fn ($q) => $q->whereNull('variant_id'),
                )
                ->value('quantity') ?? '0.0000';

            /** @var numeric-string $newQuantity */
            $newQuantity = bcadd($currentStock, $delta, 4);

            $this->stockAdjustmentService->adjust(
                productId: $item->product_id,
                locationId: $item->location_id,
                newQuantity: $newQuantity,
                reason: $reference,
                userId: $event->completedBy,
                expectedCompanyId: $counting->company_id,
                variantId: $item->variant_id,
            );

            $adjustedCount++;
        }

        Log::info('ApplyStockAdjustments: Stock adjustments applied', [
            'counting_id' => $event->countingId,
            'counting_number' => $counting->counting_number,
            'items_adjusted' => $adjustedCount,
            'items_total' => $counting->items->count(),
        ]);
    }
}
