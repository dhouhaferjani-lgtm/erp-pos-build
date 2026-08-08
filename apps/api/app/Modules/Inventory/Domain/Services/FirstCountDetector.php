<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Shared\Domain\Enums\StockMovementReferenceType;
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
 *           OR (movement_type = 'adjustment'
 *               AND reason = 'adjustment_positive'
 *               AND reference_type = 'stock_adjustment')
 *
 * NULL-reason receipts ARE baseline: WeightedAverageCostService::recordPurchase
 * writes reason-less receipt rows. POS sales (issue) and POS returns
 * (receipt with reason pos_return / customer_return) never establish a baseline,
 * so a location can sell during onboarding and its first physical count still
 * lands as an opening balance.
 *
 * The THIRD arm is DPA V7 (plan D2a). Before V7, a manual "receive" wrote
 * MovementType::Receipt with a NULL reason and therefore established a baseline;
 * V7 routes that same physical act through the `stock_adjustments` document,
 * which writes MovementType::Adjustment. Without this arm the reclassification
 * would silently flip a pending onboarding count from postCountCorrection() to
 * postCountOpening()/applyOpeningWac() — i.e. move the product's cost at rest
 * from "untouched" to "set/blended from the count's opening unit cost".
 *
 * The `reference_type` conjunct is what makes the arm FORWARD-ONLY by
 * construction rather than by assertion. Today's shipped modal already writes
 * (movement_type='adjustment', reason='adjustment_positive') rows, and this
 * predicate has no time bound — a two-column arm would retro-include every one
 * of them and flip live pending onboarding counts. Only V7's writer stamps
 * `reference_type = 'stock_adjustment'`; every legacy manual row is NULL there,
 * because `adjust()` passes no reference type and `recordMovement()` persists
 * `$referenceType?->value`.
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
                })
                // DPA V7 / D2a. THREE columns, not two: the reference_type
                // conjunct excludes every legacy manual adjustment row (which is
                // NULL there) by construction, so the arm cannot retro-flip a
                // pending onboarding count on deploy.
                ->orWhere(function ($adjustment): void {
                    $adjustment
                        ->where('movement_type', MovementType::Adjustment->value)
                        ->where('reason', MovementReason::AdjustmentPositive->value)
                        ->where('reference_type', StockMovementReferenceType::StockAdjustment->value);
                });
        });

        return ! $query->exists();
    }
}
