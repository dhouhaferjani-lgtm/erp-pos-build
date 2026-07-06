<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\DTOs\ReplayAuditDto;
use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Domain\Services\MovementReplayService;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use Carbon\CarbonInterface;
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
        private readonly MovementReplayService $replayService,
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
        $window = $counting->ambiguity_window_minutes;
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

            if ($finalQty === null) {
                continue;
            }

            // Legacy path: counts created before the replay feature carry no
            // final_qty_as_of — apply the exact historical `final − theoretical`
            // delta so pre-existing behaviour (and its regression sentinels)
            // stays byte-for-byte identical.
            if ($item->final_qty_as_of === null) {
                if ($this->applyLegacyDelta($item, $counting->company_id, $reference, $event->completedBy)) {
                    $adjustedCount++;
                }

                continue;
            }

            if ($this->applyReplay($item, $counting, $window)) {
                $adjustedCount++;
            }
        }

        Log::info('ApplyStockAdjustments: Stock adjustments applied', [
            'counting_id' => $event->countingId,
            'counting_number' => $counting->counting_number,
            'items_adjusted' => $adjustedCount,
            'items_total' => $counting->items->count(),
        ]);
    }

    /**
     * Exact legacy behaviour: `delta = final − theoretical` applied on top of
     * current stock, preserving movements during the counting period.
     */
    private function applyLegacyDelta(
        InventoryCountingItem $item,
        string $companyId,
        string $reference,
        string $completedBy,
    ): bool {
        $finalQty = $item->final_qty;
        $theoreticalQty = $item->theoretical_qty;

        if ($finalQty === null || $finalQty === $theoreticalQty) {
            return false;
        }

        $delta = bcsub($finalQty, $theoreticalQty, 4);

        // Scope the current-stock lookup to the variant row when the item was
        // counted against a specific variant (Task 20).
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
            userId: $completedBy,
            expectedCompanyId: $companyId,
            variantId: $item->variant_id,
            reasonCode: MovementReason::CountCorrection,
        );

        return true;
    }

    /**
     * Replay path: basket-window pre-check (needs no lock), then the locked
     * replay-and-post in StockAdjustmentService. Persists the replay audit +
     * flags on the item.
     */
    private function applyReplay(
        InventoryCountingItem $item,
        InventoryCounting $counting,
        int $window,
    ): bool {
        /** @var CarbonInterface $asOf */
        $asOf = $item->final_qty_as_of;

        // Basket-window guard (owned here — it needs no row lock and lets us
        // attribute the correct flag). A movement within ±window of the count
        // instant means the counted quantity is ambiguous; skip posting.
        if ($this->replayService->hasMovementNear($item->product_id, $item->location_id, $item->variant_id, $asOf, $window)) {
            $this->flagItem($item, CountingItemFlagReason::BasketWindow, $asOf);

            return false;
        }

        $location = Location::where('company_id', $counting->company_id)->find($item->location_id);
        // onboarding_mode is the precise signal for opening semantics; the
        // stock-policy resolver derives Off from it (A4), but the boolean is
        // what decides sell-before-count / first-count-as-opening here.
        $onboarding = $location !== null && $location->onboarding_mode;

        $openingUnitCost = $this->resolveOpeningUnitCost($item, $counting->company_id);

        /** @var numeric-string $finalQty */
        $finalQty = (string) $item->final_qty;

        $audit = $this->stockAdjustmentService->applyCountResult(
            productId: $item->product_id,
            locationId: $item->location_id,
            variantId: $item->variant_id,
            finalQty: $finalQty,
            finalQtyAsOf: $asOf,
            ambiguityWindowMinutes: $window,
            onboarding: $onboarding,
            openingUnitCost: $openingUnitCost,
        );

        // Null return means the negative-at-apply guard tripped (basket window
        // was already excluded above) — nothing was posted; leave for review.
        if ($audit === null) {
            $this->flagItem($item, CountingItemFlagReason::NegativeAtApply, $asOf);

            return false;
        }

        /** @var numeric-string $expectedAtApply */
        $expectedAtApply = $audit->expectedAtApply;
        $item->expected_qty_at_apply = $expectedAtApply;
        $item->replay_audit = $audit->toArray();

        // An opening posted with no cost is not blocked, but is flagged so the
        // review page can backfill the cost.
        if ($onboarding && $openingUnitCost === null) {
            $item->is_flagged = true;
            if ($item->flag_reason === null) {
                $item->flag_reason = 'pending_opening_cost';
            }
        }

        $item->save();

        return true;
    }

    /**
     * Opening cost for the line: the review-page override when present, else the
     * product's current cost. May be null (cost-less opening — not blocking).
     *
     * @return numeric-string|null
     */
    private function resolveOpeningUnitCost(InventoryCountingItem $item, string $companyId): ?string
    {
        if ($item->opening_unit_cost !== null) {
            /** @var numeric-string $override */
            $override = (string) $item->opening_unit_cost;

            return $override;
        }

        $costPrice = Product::where('company_id', $companyId)
            ->whereKey($item->product_id)
            ->value('cost_price');

        if ($costPrice === null) {
            return null;
        }

        /** @var numeric-string $cost */
        $cost = (string) $costPrice;

        return $cost;
    }

    /**
     * Append a blocking flag reason to the item (dedup) and persist a best-effort
     * replay audit for the review page. Nothing was posted for a flagged item.
     */
    private function flagItem(
        InventoryCountingItem $item,
        CountingItemFlagReason $reason,
        CarbonInterface $asOf,
    ): void {
        $reasons = $item->flag_reasons ?? [];
        if (! in_array($reason->value, $reasons, true)) {
            $reasons[] = $reason->value;
        }

        $item->flag_reasons = $reasons;
        if ($reason->isBlocking()) {
            $item->is_flagged = true;
        }

        $audit = $this->buildAudit($item, $asOf);
        /** @var numeric-string $expectedAtApply */
        $expectedAtApply = $audit->expectedAtApply;
        $item->expected_qty_at_apply = $expectedAtApply;
        $item->replay_audit = $audit->toArray();
        $item->save();
    }

    /**
     * Best-effort replay snapshot for a flagged (unposted) item. Read outside
     * the stock-level lock — annotation only, never drives a stock write.
     */
    private function buildAudit(InventoryCountingItem $item, CarbonInterface $asOf): ReplayAuditDto
    {
        $now = now();
        $scale = InventoryScale::QUANTITY_SCALE;

        $replayedDelta = $this->replayService->signedDelta($item->product_id, $item->location_id, $item->variant_id, $asOf, $now);

        /** @var numeric-string $onHand */
        $onHand = (string) (StockLevel::where('product_id', $item->product_id)
            ->where('location_id', $item->location_id)
            ->when(
                $item->variant_id !== null,
                fn ($q) => $q->where('variant_id', $item->variant_id),
                fn ($q) => $q->whereNull('variant_id'),
            )
            ->value('quantity') ?? '0.0000');

        /** @var numeric-string $finalQty */
        $finalQty = (string) $item->final_qty;
        $expectedNow = bcadd($finalQty, $replayedDelta, $scale);

        return new ReplayAuditDto(
            windowFrom: $asOf->toIso8601String(),
            windowTo: $now->toIso8601String(),
            replayedDelta: $replayedDelta,
            onHandAtApply: bcadd($onHand, '0', $scale),
            expectedAtApply: $expectedNow,
        );
    }
}
