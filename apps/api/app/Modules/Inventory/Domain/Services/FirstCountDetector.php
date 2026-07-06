<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a count is the FIRST count of a stock line — i.e. whether it
 * should post opening-balance semantics (absolute, with cost) rather than a
 * shrinkage variance correction. Live-inventory-counting task B3 (spec §5).
 *
 * A count is "first" iff NO prior supply-side BASELINE movement exists for the
 * `(product, location[, variant])` line:
 *
 *   Baseline = movement_type IN ('opening', 'transfer_in')
 *           OR (movement_type = 'receipt'
 *               AND (reason IS NULL OR reason NOT IN ('pos_return', 'customer_return')))
 *
 * NULL-reason receipts ARE baseline: WeightedAverageCostService::recordPurchase
 * writes reason-less receipt rows. POS sales (issue) and POS returns
 * (receipt with reason pos_return / customer_return) never establish a baseline,
 * so a location can sell during onboarding and its first physical count still
 * lands as an opening balance.
 *
 * Reads `stock_movements` directly (query builder, no CompanyContext dependency)
 * so it is safe in the queued finalize listener that runs with no company bound.
 */
final class FirstCountDetector
{
    public function isFirstCount(string $productId, string $locationId, ?string $variantId): bool
    {
        $query = DB::table('stock_movements')
            ->where('product_id', $productId)
            ->where('location_id', $locationId);

        if ($variantId === null) {
            $query->whereNull('variant_id');
        } else {
            $query->where('variant_id', $variantId);
        }

        $query->where(function ($baseline): void {
            $baseline
                ->whereIn('movement_type', [
                    MovementType::Opening->value,
                    MovementType::TransferIn->value,
                ])
                ->orWhere(function ($receipt): void {
                    $receipt
                        ->where('movement_type', MovementType::Receipt->value)
                        ->where(function ($reasonFilter): void {
                            $reasonFilter
                                ->whereNull('reason')
                                ->orWhereNotIn('reason', [
                                    MovementReason::POSReturn->value,
                                    MovementReason::CustomerReturn->value,
                                ]);
                        });
                });
        });

        return ! $query->exists();
    }
}
