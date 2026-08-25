<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Domain\StockMovement;
use Carbon\CarbonImmutable;
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
     * whose event time falls in [from, to] — INCLUSIVE on both bounds.
     *
     * ## The baseline this defines (campaign W4-6, gate r1 F-2)
     *
     * `from` is the instant the shelf was physically counted. The counted
     * quantity is taken as the truth AT that instant, so:
     *
     *  - everything that happened STRICTLY BEFORE it is already reflected in
     *    what the counter saw — it is part of the baseline and must NOT be
     *    replayed (re-adding it would double-count the shelf);
     *  - everything from that instant onwards is neutralised exactly ONCE, so
     *    `expected_now = counted + Σ` restores the count onto today's on-hand.
     *
     * The boundary itself is INCLUSIVE, and that is a deliberate fail-safe
     * rather than a rounding detail. `count_N_at_estimate` and
     * `occurred_at`/`created_at` are both stored at second precision, so a sale
     * rung up in the same second as the count is genuinely ambiguous. Excluding
     * it (the pre-2026-08-25 `>` semantics) treats it as already-counted and
     * posts a phantom GAIN of its magnitude — stock the shop does not have, and
     * a wrong shrinkage/gain journal entry the moment
     * `inventory.count_correction_gl_posting_enabled` is flipped. Including it
     * resolves the same line at variance ZERO. Between two unprovable readings,
     * take the one that invents no stock.
     *
     * ## The same-second tie-break (gate r2, NEW-1)
     *
     * Closing the boundary is necessary but not sufficient: `[from, to]` decides
     * the same-second case in favour of ONE reading of the counter (they had not
     * seen the movement). Take the other reading — the sale was rung up at `from`
     * and the counter walked past AFTER it — and the same units are subtracted
     * twice. A timestamp cannot separate the two; it has no information left.
     * INSERTION ORDER does, and `$marker` carries it: the last
     * `stock_movements.id` that existed on this line when the count was
     * SUBMITTED. A boundary-second movement at or below it was already there
     * when the counter reported (baseline); above it, it arrived afterwards
     * (neutralised). `stock_movements.id` is a UUIDv7, so lexicographic order is
     * creation order.
     *
     * The marker refines ONLY the boundary second. Everything strictly after
     * `from` is neutralised and everything strictly before it is baseline,
     * marker or no marker — because `from` may be a DEVICE instant hours earlier
     * than the marker was taken (an offline count that synced later), and pure
     * id ordering would then silently stop neutralising every sale made between
     * the count and its sync. Time decides what time can decide; order decides
     * only the tie.
     *
     * With `$marker` null (a legacy row, or a line counted before the marker
     * columns shipped) the boundary second stays inclusive — the r1 semantics,
     * unchanged.
     *
     * A single SUM query; the result is normalized to
     * `InventoryScale::QUANTITY_SCALE` via bcadd so a NULL sum (no matching
     * rows) becomes the canonical zero string rather than PHP null, and any
     * driver-returned numeric type is forced through bcmath rather than a
     * float cast.
     *
     * @param  string|null  $marker  Last `stock_movements.id` visible when the count was submitted.
     * @return numeric-string signed delta at InventoryScale::QUANTITY_SCALE (may be negative)
     */
    public function signedDelta(
        string $productId,
        string $locationId,
        ?string $variantId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $marker = null,
    ): string {
        $fromBoundary = $this->boundary($from);

        $query = $this->scopedQuery($productId, $locationId, $variantId)
            ->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$fromBoundary])
            ->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$this->boundary($to)]);

        if ($marker !== null) {
            // Boundary-second rows only: keep the ones that arrived AFTER the
            // count was submitted, drop the ones that were already there.
            $query->where(function (Builder $boundary) use ($fromBoundary, $marker): void {
                $boundary
                    ->whereRaw('COALESCE(occurred_at, created_at) > ?', [$fromBoundary])
                    ->orWhere('id', '>', $marker);
            });
        }

        /** @var string|int|float|null $sum */
        $sum = $query->selectRaw('SUM(quantity_after - quantity_before) as delta')->value('delta');

        /** @var numeric-string $sumString */
        $sumString = $sum === null ? '0' : (string) $sum;

        return bcadd($sumString, '0', InventoryScale::QUANTITY_SCALE);
    }

    /**
     * Compute the replay result without mutating stock. The locked finalize
     * path and the pre-finalize preview both call this method so their decimal
     * arithmetic cannot drift apart.
     *
     * @param  numeric-string  $finalQty
     * @param  numeric-string  $onHandNow
     */
    public function compute(
        string $productId,
        string $locationId,
        ?string $variantId,
        string $finalQty,
        CarbonInterface $from,
        CarbonInterface $to,
        string $onHandNow,
        ?string $marker = null,
    ): ReplayComputation {
        $movementsSinceCount = $this->signedDelta($productId, $locationId, $variantId, $from, $to, $marker);

        return $this->computeFromDelta($finalQty, $onHandNow, $movementsSinceCount);
    }

    /**
     * Batch preview replay into one movement query, avoiding three reads per
     * counting line on coarse scopes.
     *
     * @param  list<ReplayPreviewInput>  $inputs
     * @return array<string, ReplayBatchResult>
     */
    public function computeMany(array $inputs, CarbonInterface $to, string $companyId): array
    {
        if ($inputs === []) {
            return [];
        }

        $minimumFrom = null;
        $productIds = [];
        $locationIds = [];
        foreach ($inputs as $input) {
            $candidate = CarbonImmutable::instance($input->from)->subMinutes($input->windowMinutes);
            if ($minimumFrom === null || $candidate->lt($minimumFrom)) {
                $minimumFrom = $candidate;
            }
            $productIds[$input->productId] = true;
            $locationIds[$input->locationId] = true;
        }

        $rows = StockMovement::query()
            ->where('company_id', $companyId)
            ->whereIn('product_id', array_keys($productIds))
            ->whereIn('location_id', array_keys($locationIds))
            ->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$this->boundary($minimumFrom)])
            ->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$this->boundary($to)])
            ->get(['id', 'product_id', 'location_id', 'variant_id', 'quantity_before', 'quantity_after', 'occurred_at', 'created_at']);

        /** @var array<string, list<StockMovement>> $byGrain */
        $byGrain = [];
        foreach ($rows as $row) {
            $key = $this->grainKey((string) $row->product_id, (string) $row->location_id, $row->variant_id !== null ? (string) $row->variant_id : null);
            $byGrain[$key][] = $row;
        }

        $results = [];
        foreach ($inputs as $input) {
            $delta = bcadd('0', '0', InventoryScale::QUANTITY_SCALE);
            $near = false;
            $from = CarbonImmutable::instance($input->from);
            $nearFrom = $from->subMinutes($input->windowMinutes);
            $nearTo = $from->addMinutes($input->windowMinutes);
            foreach ($byGrain[$this->grainKey($input->productId, $input->locationId, $input->variantId)] ?? [] as $row) {
                $eventAtValue = $row->occurred_at ?? $row->created_at;
                if ($eventAtValue === null) {
                    continue;
                }
                $eventAt = CarbonImmutable::instance($eventAtValue);
                // Mirror of signedDelta()'s tie-break: at the boundary second the
                // marker decides, everywhere else the timestamp does. The preview
                // and the apply must never disagree about a line.
                $inWindow = $eventAt->gt($from)
                    || ($eventAt->eq($from) && ($input->marker === null || (string) $row->id > $input->marker));
                if ($inWindow && $eventAt->lte($to)) {
                    $rowDelta = bcsub($row->quantity_after, $row->quantity_before, InventoryScale::QUANTITY_SCALE);
                    $delta = bcadd($delta, $rowDelta, InventoryScale::QUANTITY_SCALE);
                }
                if ($eventAt->betweenIncluded($nearFrom, $nearTo)) {
                    $near = true;
                }
            }

            $results[$input->key] = new ReplayBatchResult(
                $this->computeFromDelta($input->finalQty, $input->onHandNow, $delta),
                $near,
            );
        }

        return $results;
    }

    /**
     * @param  numeric-string  $finalQty
     * @param  numeric-string  $onHandNow
     * @param  numeric-string  $delta
     */
    private function computeFromDelta(string $finalQty, string $onHandNow, string $delta): ReplayComputation
    {
        $scale = InventoryScale::QUANTITY_SCALE;
        $expectedNow = bcadd($finalQty, $delta, $scale);

        return new ReplayComputation(
            movementsSinceCount: $delta,
            onHandNow: $onHandNow,
            expectedNow: $expectedNow,
            adjustment: bcsub($expectedNow, $onHandNow, $scale),
        );
    }

    private function grainKey(string $productId, string $locationId, ?string $variantId): string
    {
        return $productId."\0".$locationId."\0".($variantId ?? '');
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
    /**
     * Whether the count instant's OWN second carries a movement on this grain
     * (lane P-1, gate r1 F-2).
     *
     * This is the exact and only condition under which a NULL
     * `final_qty_movement_marker` changes the replayed delta: the marker branch
     * in {@see self::signedDelta()} narrows nothing but the boundary second, so
     * with an empty boundary second a marker-less line and a marker-bearing line
     * produce identical arithmetic.
     *
     * Callers use it to decide whether a marker-less line is genuinely
     * ambiguous. Answering the cheaper question ("is the marker null?") would
     * withhold the journal entry of every line counted before the marker columns
     * shipped, including the overwhelming majority whose replay is not in doubt
     * at all — a correct entry suppressed is a cost, not a saving.
     */
    public function boundarySecondIsAmbiguous(
        string $productId,
        string $locationId,
        ?string $variantId,
        CarbonInterface $countInstant,
    ): bool {
        $boundary = $this->boundary($countInstant);

        return $this->scopedQuery($productId, $locationId, $variantId)
            ->whereRaw('COALESCE(occurred_at, created_at) = ?', [$boundary])
            ->exists();
    }

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
     * stored boundary row, breaking the exact-boundary [from, to] semantics.
     * Converts to UTC before formatting to ensure consistent comparison
     * regardless of the caller's timezone.
     */
    private function boundary(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format('Y-m-d H:i:s');
    }
}
