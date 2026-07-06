<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\InventoryScale;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation math core for live-inventory-counting: replays
 * `stock_movements` by event time to answer "what changed on this stock line
 * since the count window opened/closed".
 *
 * Classification is by the ROW's signed delta
 * (`quantity_after - quantity_before`) ONLY — never by `MovementType`.
 * `MovementType::Adjustment` is bidirectional (a count correction can go
 * either way) and a reversal row self-cancels through its OWN before/after,
 * not through inspecting `movement_type` or `reverses_movement_id`. See
 * `StockMovement::directionForRow()` for the same principle applied per-row.
 *
 * Event time is `COALESCE(occurred_at, created_at)` — pre-A1 rows have no
 * `occurred_at` until the backfill migration runs, so every read here must
 * fall back to `created_at` rather than assume the column is populated.
 */
final class MovementReplayService
{
    /**
     * Σ(quantity_after − quantity_before) over the stock line's movements
     * whose event time falls in (from, to] — exclusive start, inclusive end.
     *
     * A single SUM query; the result is normalized to
     * `InventoryScale::QUANTITY_SCALE` via bcadd so a NULL sum (no matching
     * rows) becomes the canonical zero string rather than PHP null, and any
     * driver-returned numeric type is forced through bcmath rather than a
     * float cast.
     */
    public function signedDelta(
        string $productId,
        string $locationId,
        ?string $variantId,
        CarbonInterface $from,
        CarbonInterface $to,
    ): string {
        $query = $this->scopedQuery($productId, $locationId, $variantId)
            ->whereRaw('COALESCE(occurred_at, created_at) > ?', [$this->boundary($from)])
            ->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$this->boundary($to)]);

        /** @var string|int|float|null $sum */
        $sum = $query->selectRaw('SUM(quantity_after - quantity_before) as delta')->value('delta');

        /** @var numeric-string $sumString */
        $sumString = $sum === null ? '0' : (string) $sum;

        return bcadd($sumString, '0', InventoryScale::QUANTITY_SCALE);
    }

    /**
     * True if any movement exists for the stock line with event time within
     * ±$windowMinutes of $instant (inclusive on both bounds).
     */
    public function hasMovementNear(
        string $productId,
        string $locationId,
        ?string $variantId,
        CarbonInterface $instant,
        int $windowMinutes,
    ): bool {
        // `clone` (not a mutating ->subMinutes/->addMinutes on $instant
        // itself) so a mutable Carbon instance passed in by the caller is
        // never altered as a side effect of this read.
        $from = (clone $instant)->subMinutes($windowMinutes);
        $to = (clone $instant)->addMinutes($windowMinutes);

        return $this->scopedQuery($productId, $locationId, $variantId)
            ->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$this->boundary($from)])
            ->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$this->boundary($to)])
            ->exists();
    }

    /**
     * Base query scoped to one stock line: product + location + the variant
     * predicate. `variant_id IS NULL` when `$variantId` is null, otherwise
     * `variant_id = :v` — never both, so variant B's movements are invisible
     * to variant A's replay and to the null-variant (base product) line.
     */
    private function scopedQuery(string $productId, string $locationId, ?string $variantId): Builder
    {
        $query = DB::table('stock_movements')
            ->where('product_id', $productId)
            ->where('location_id', $locationId);

        if ($variantId === null) {
            $query->whereNull('variant_id');
        } else {
            $query->where('variant_id', $variantId);
        }

        return $query;
    }

    /**
     * Formats a window boundary to second precision, matching the
     * `timestampTz` (precision 0) storage of `occurred_at`/`created_at` — a
     * value carrying sub-second precision would never compare equal to a
     * stored boundary row, breaking the exact-boundary (from, to] semantics.
     */
    private function boundary(CarbonInterface $instant): string
    {
        return $instant->format('Y-m-d H:i:s');
    }
}
