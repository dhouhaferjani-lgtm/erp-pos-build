<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\DTOs\ReplayAuditDto;
use App\Modules\Inventory\Application\Services\CountCorrectionGlPostingResolver;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Domain\Services\CountingReplayGuardEvaluator;
use App\Modules\Inventory\Domain\Services\MovementReplayService;
use App\Modules\Inventory\Domain\Services\OpeningCostGate;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
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

    /**
     * Cost scale (COST_SCALE = 6) — matches `opening_unit_cost`'s
     * `decimal(_,6)` storage and StockAdjustmentService/WeightedAverageCostService.
     */
    private const COST_SCALE = 6;

    /**
     * Per-JOB memo of the resolved count-correction GL-posting answer, keyed by
     * company id (lane P-1).
     *
     * The resolver reads two tables; a 400-line count would otherwise repeat
     * that per varying line. It is reset at the top of every `handle()` so a
     * reused listener instance (the retry path, and every test that fires twice)
     * cannot serve a stale answer after an operator flips the setting.
     *
     * @var array<string, bool>
     */
    private array $glPostingEnabled = [];

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly MovementReplayService $replayService,
        private readonly OpeningCostGate $openingCostGate,
        private readonly CountingReplayGuardEvaluator $guardEvaluator,
        private readonly InventoryGlPostingBuffer $glBuffer,
        private readonly CountCorrectionGlPostingResolver $glPostingResolver,
    ) {}

    public function handle(InventoryCountingCompleted $event): void
    {
        $this->glPostingEnabled = [];

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

        // T21 RULING (fiscal R3-3) — the flush needs a ROOT FRAME that did not
        // exist. handle() opened NO transaction and applyCountResult()'s is PER
        // ITEM, so "flush after the loop" sat at depth 0 (flushIfOutermost can
        // never post there, and D-10 forbids autocommit posting) while flushing
        // inside the loop would take the company advisory before item N+1's
        // ProductCostLock / stock_levels row locks — violating I-1. Neither is
        // implementable. So: ONE transaction around the whole item loop, enqueue
        // per item, flush at this transaction's tail.
        //
        // The consequence, stated rather than discovered: the per-item
        // boundaries the applyReplay() comment defends become SAVEPOINTS, so an
        // item-N failure no longer commits items 1..N-1. This listener is
        // ShouldQueue with $tries = 3 and the retry re-runs the whole counting,
        // which the `replay_audit` marker and the legacy existing-movement probe
        // already make idempotent. Pinned by
        // CountCorrectionGlPostingTest::test_an_item_that_throws_...
        DB::transaction(function () use ($counting, $event, $reference, $window, &$adjustedCount): void {
            $currencyCode = $this->resolveCurrencyCode($counting->company_id);

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
                    if ($this->applyLegacyDelta($item, $counting->company_id, $reference, $event->completedBy, $counting->id, $currencyCode)) {
                        $adjustedCount++;
                    }

                    continue;
                }

                // Idempotency guard (queue-retry double-apply defense). This listener
                // is ShouldQueue with $tries=3; if item N throws after items 1..N-1
                // committed, the WHOLE job replays. The replay path stamps
                // `replay_audit` in the SAME transaction as its stock movement (see
                // applyReplay), so a non-null marker proves this item already reached
                // a terminal state on a prior attempt — posted OR flagged (flags carry
                // an audit too). Re-running would re-sum the prior correction inside a
                // fresh replay window and double-apply. Skip it.
                if ($item->replay_audit !== null) {
                    Log::info('ApplyStockAdjustments: Skipping already-applied replay item (queue retry)', [
                        'counting_id' => $event->countingId,
                        'item_id' => $item->id,
                    ]);

                    continue;
                }

                if ($this->applyReplay($item, $counting, $window, $event->completedBy, $currencyCode)) {
                    $adjustedCount++;
                }
            }

            // The tail of the ROOT frame: every count correction this job
            // enqueued posts here, once, with the company advisory taken after
            // the last item released its product-grain locks.
            $this->glBuffer->flushIfOutermost();
        });

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
     *
     * @param  string  $countingId  Counting-document UUID stamped as the movement's
     *                              `reference_id` (with `reference_type` =
     *                              StockMovementReferenceType::InventoryCounting).
     *                              Additive to the free-text `COUNTING:{number}`
     *                              label, which remains the legacy idempotency key.
     * @param  string  $currencyCode  The company's ISO 4217 code, resolved ONCE per job.
     *                                Explicit because this listener is queued and runs with no
     *                                CompanyContext, where a bare no-arg scale resolution throws
     *                                (house rules 19/20).
     */
    private function applyLegacyDelta(
        InventoryCountingItem $item,
        string $companyId,
        string $reference,
        string $completedBy,
        string $countingId,
        string $currencyCode,
    ): bool {
        $finalQty = $item->final_qty;
        $theoreticalQty = $item->theoretical_qty;

        if ($finalQty === null || $finalQty === $theoreticalQty) {
            return false;
        }

        // Idempotency guard (queue-retry double-apply defense). The legacy path
        // has no per-item marker column; its applied-marker IS the posted
        // movement, keyed by reference `COUNTING:{number}`. Because one counting
        // number is shared across all its lines, discriminate by
        // (product, location, variant) so each line is checked independently.
        // A hit means attempt 1 already posted this line's correction; re-running
        // would apply `final − theoretical` a second time on top of the already
        // corrected on-hand. Skip it.
        $alreadyApplied = StockMovement::query()
            ->where('product_id', $item->product_id)
            ->where('location_id', $item->location_id)
            ->when(
                $item->variant_id !== null,
                fn ($q) => $q->where('variant_id', $item->variant_id),
                fn ($q) => $q->whereNull('variant_id'),
            )
            ->where('reference', $reference)
            ->exists();

        if ($alreadyApplied) {
            Log::info('ApplyStockAdjustments: Skipping already-applied legacy item (existing COUNTING movement)', [
                'item_id' => $item->id,
                'reference' => $reference,
            ]);

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

        $movement = $this->stockAdjustmentService->adjust(
            productId: $item->product_id,
            locationId: $item->location_id,
            newQuantity: $newQuantity,
            reason: $reference,
            userId: $completedBy,
            expectedCompanyId: $companyId,
            variantId: $item->variant_id,
            reasonCode: MovementReason::CountCorrection,
            referenceType: StockMovementReferenceType::InventoryCounting,
            referenceId: $countingId,
        );

        // T21 — the legacy path posts too, on the SAME basis as the replay path
        // (the row's own persisted unit_cost). One basis, on the row, both paths.
        $this->enqueueCountCorrectionGl($movement, $currencyCode, $completedBy);

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
        string $completedBy,
        string $currencyCode,
    ): bool {
        /** @var CarbonInterface $asOf */
        $asOf = $item->final_qty_as_of;

        $hasMovementNear = $this->replayService->hasMovementNear($item->product_id, $item->location_id, $item->variant_id, $asOf, $window);

        $location = Location::where('company_id', $counting->company_id)->find($item->location_id);
        // onboarding_mode is the precise signal for opening semantics; the
        // stock-policy resolver derives Off from it (A4), but the boolean is
        // what decides sell-before-count / first-count-as-opening here.
        $onboarding = $location !== null && $location->onboarding_mode;

        $item->loadMissing('product');
        $openingGate = $this->openingCostGate->evaluateItem($item, $onboarding);

        // W4-6. Every pre-apply reason is RECORDED; only a reason that answers
        // `blocksStockApplication()` withholds the posting. `basket_window` is
        // recorded and does NOT withhold: the replay window inside
        // applyCountResult() is what keeps an in-count sale counted exactly once,
        // and suppressing the whole line on the strength of a nearby movement
        // discarded the very shrinkage the count exists to find.
        $preApplyReasons = $this->guardEvaluator->preApply($hasMovementNear, $openingGate['opening_cost_missing']);

        // Lane P-1, gate r1 F-2. A line with no `final_qty_movement_marker`
        // whose boundary second actually carries a movement has an UNPROVABLE
        // replay: W4-6's order-based tie-break cannot run, so the r1 inclusive
        // boundary may subtract that movement a second time. The shelf is still
        // corrected — refusing would strand the operator mid-count — but the
        // ledger is not, because a wrong journal entry is far more expensive to
        // undo than a wrong shelf. Recorded as a reason so the reviewer sees it
        // and the report can say why the value is missing.
        if ($item->final_qty_movement_marker === null
            && $this->replayService->boundarySecondIsAmbiguous($item->product_id, $item->location_id, $item->variant_id, $asOf)) {
            $preApplyReasons[] = CountingItemFlagReason::MissingBoundaryMarker;
        }

        $glWithheld = false;
        foreach ($preApplyReasons as $reason) {
            if ($reason->blocksGlPosting()) {
                $glWithheld = true;
                break;
            }
        }

        $preApplyBlock = null;
        foreach ($preApplyReasons as $reason) {
            if ($reason->blocksStockApplication()) {
                $preApplyBlock = $reason;
                break;
            }
        }

        if ($preApplyBlock !== null) {
            $this->flagItem($item, $preApplyBlock, $asOf, $preApplyReasons);

            return false;
        }

        // Advisory reasons are stamped BEFORE the posting so the annotation and
        // the posting share one savepoint. They do NOT survive a throw: handle()
        // wraps the whole item loop in ONE root transaction (see the T21 note
        // there) with no per-item catch, so an item-N failure unwinds this save
        // along with everything else — which is exactly what
        // CountCorrectionGlPostingTest::test_an_item_that_throws_... pins (0
        // movements AND 0 entries). Durability is not needed: the queue retry
        // re-evaluates the line because `replay_audit` — the idempotency marker
        // — is still null, and re-annotates it.
        $this->annotateItem($item, $preApplyReasons);

        $openingUnitCost = $this->resolveOpeningUnitCost($item, $counting->company_id);

        /** @var numeric-string $finalQty */
        $finalQty = (string) $item->final_qty;

        // Post the movement AND stamp the applied-marker (replay_audit) in ONE
        // transaction. applyCountResult opens its own transaction; nesting it here
        // makes its stock write a savepoint of THIS transaction, so the movement
        // and the marker commit (or roll back) together. Without this, a crash
        // between the movement commit and the marker save would leave a posted
        // movement with no marker — and the queue retry (idempotency guard keys on
        // replay_audit) would re-sum that movement in a fresh window and
        // double-apply. This is the fix for the finalize double-apply blocker.
        $countingId = $counting->id;
        $marker = $item->final_qty_movement_marker;

        return DB::transaction(function () use ($item, $window, $asOf, $onboarding, $openingUnitCost, $finalQty, $countingId, $completedBy, $currencyCode, $marker, $glWithheld): bool {
            $audit = $this->stockAdjustmentService->applyCountResult(
                productId: $item->product_id,
                locationId: $item->location_id,
                variantId: $item->variant_id,
                finalQty: $finalQty,
                finalQtyAsOf: $asOf,
                ambiguityWindowMinutes: $window,
                onboarding: $onboarding,
                openingUnitCost: $openingUnitCost,
                // Document linkage (DPA S0): the count movement points back at the
                // counting row itself, not just the free-text COUNT_REPLAY label.
                referenceType: StockMovementReferenceType::InventoryCounting,
                referenceId: $countingId,
                // T21 — the GL sink fires ONLY for the postCountCorrection()
                // branch. postCountOpening() writes MovementType::Opening /
                // MovementReason::OpeningBalance, which is outside the seam, so
                // the onboarding first count still posts no shrinkage/gain leg.
                onCountCorrection: function (StockMovement $movement) use ($currencyCode, $completedBy, $glWithheld): void {
                    if ($glWithheld) {
                        // P-1 F-2: the movement is written and costed, so the
                        // entry can still be built by hand once the line is
                        // reviewed. Only the automatic posting is withheld.
                        Log::warning('ApplyStockAdjustments: count correction applied WITHOUT a journal entry — replay boundary is ambiguous', [
                            'movement_id' => $movement->id,
                            'company_id' => $movement->company_id,
                            'reason' => CountingItemFlagReason::MissingBoundaryMarker->value,
                        ]);

                        return;
                    }

                    $this->enqueueCountCorrectionGl($movement, $currencyCode, $completedBy);
                },
                // Same-second tie-break (gate r2 NEW-1) — see
                // MovementReplayService::signedDelta().
                finalQtyMovementMarker: $marker,
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
            $item->save();

            return true;
        });
    }

    /**
     * Hand ONE count_correction movement to the inventory GL buffer (T21).
     *
     * Enqueueing is pure — no database work happens until `flushIfOutermost()`
     * at the tail of handle()'s root transaction — so calling it from inside a
     * per-item savepoint, while the item still holds its ProductCostLock and
     * stock_levels row, cannot take the company advisory early and cannot
     * invert the I-1 lock order.
     *
     * Every value is copied off the PERSISTED row. The amount the posting
     * service derives is `unit_cost x |quantity_after − quantity_before|`, so
     * both counting paths share one basis and neither re-reads a WAC that may
     * have moved since the movement was written.
     *
     * ## Gated per COMPANY, seeded ON (lane P-1, owner ruling 2026-08-25)
     *
     * The gate used to be a bare `config()` read defaulted FALSE by the
     * OQ-12/H-5 deploy blocker. The owner superseded that blocker: posting is
     * seeded ON per country and is tenant-editable, so the question is now
     * "is it on for THIS company", answered by
     * {@see CountCorrectionGlPostingResolver} through the chain
     * company override -> country row -> system default.
     *
     * With it off the stock correction and its costed movement row still
     * happen; only the journal entry is withheld, so nothing is lost — the
     * ledger can be rebuilt from the movement rows if a tenant turns it back on.
     */
    private function enqueueCountCorrectionGl(StockMovement $movement, string $currencyCode, string $completedBy): void
    {
        if (! $this->glPostingIsEnabledFor((string) $movement->company_id)) {
            return;
        }

        $occurredAt = $movement->occurred_at ?? $movement->created_at ?? now();

        $this->glBuffer->enqueue(new MovementGlContext(
            kind: MovementGlKind::CountCorrection,
            movementId: $movement->id,
            companyId: $movement->company_id,
            currencyCode: $currencyCode,
            reason: $movement->reason ?? throw new \LogicException('Count correction movement is missing its GL reason.'),
            quantityBefore: (string) $movement->quantity_before,
            quantityAfter: (string) $movement->quantity_after,
            unitCost: (string) ($movement->unit_cost ?? '0'),
            sourceType: $movement->reference_type,
            sourceId: $movement->reference_id,
            occurredAt: \DateTimeImmutable::createFromInterface($occurredAt),
            entryDate: new \DateTimeImmutable('now'),
            // The queued listener has no authenticated user; the counting's
            // finalizer is the acting identity for the posting.
            postedByUserId: $completedBy,
            isHistorical: (bool) $movement->is_historical,
            productId: $movement->product_id,
        ));
    }

    /**
     * Whether count-correction GL posting is on for this company, resolved ONCE
     * per company per job (lane P-1).
     */
    private function glPostingIsEnabledFor(string $companyId): bool
    {
        return $this->glPostingEnabled[$companyId] ??= $this->glPostingResolver->isEnabledFor($companyId);
    }

    /**
     * The company's ISO 4217 code, resolved ONCE per job.
     *
     * Queued listeners run with NO CompanyContext, where a bare no-arg
     * `CurrencyScaleResolver::getScale()` throws (house rules 19/20). The
     * posting service scales on `MovementGlContext::$currencyCode`, so the
     * entity currency has to be carried explicitly from here.
     */
    private function resolveCurrencyCode(string $companyId): string
    {
        return (string) Company::query()->findOrFail($companyId)->currency;
    }

    /**
     * Opening cost for the line, with a zero-as-missing rule (D3).
     *
     * An EXPLICIT item-level `opening_unit_cost` (set via the opening-cost
     * endpoint) always wins — even '0', which is a deliberate zero-cost opening.
     * When it is unset, the product's `cost_price` is a fallback ONLY when it is
     * strictly positive: `cost_price` defaults to '0' and is never null, so a
     * zero fallback would silently establish a zero-cost opening. A non-positive
     * fallback therefore resolves to null (MISSING) — the caller blocks the
     * first-count opening with a `pending_opening_cost` flag instead of posting.
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

        // Non-positive product cost is treated as MISSING for opening lines.
        if (bccomp($cost, '0', self::COST_SCALE) <= 0) {
            return null;
        }

        return $cost;
    }

    /**
     * Record advisory (non-blocking) reasons on a line that IS being posted.
     *
     * `basket_window` reaches this path: the reviewer still needs to see that
     * stock moved near the count instant, but the correction is applied.
     *
     * @param  list<CountingItemFlagReason>  $reasons
     */
    private function annotateItem(InventoryCountingItem $item, array $reasons): void
    {
        if ($reasons === []) {
            return;
        }

        $existing = $item->flag_reasons ?? [];
        $flagged = (bool) $item->is_flagged;

        foreach ($reasons as $reason) {
            if (! in_array($reason->value, $existing, true)) {
                $existing[] = $reason->value;
            }

            if ($reason->isBlocking()) {
                $flagged = true;
            }
        }

        $item->flag_reasons = $existing;
        $item->is_flagged = $flagged;
        $item->save();
    }

    /**
     * Append a blocking flag reason to the item (dedup) and persist a best-effort
     * replay audit for the review page. Nothing was posted for a flagged item.
     *
     * @param  list<CountingItemFlagReason>  $alsoRecord  Advisory reasons observed on
     *                                                    the same evaluation, recorded
     *                                                    alongside the blocker.
     */
    private function flagItem(
        InventoryCountingItem $item,
        CountingItemFlagReason $reason,
        CarbonInterface $asOf,
        array $alsoRecord = [],
    ): void {
        $reasons = $item->flag_reasons ?? [];
        foreach ([...$alsoRecord, $reason] as $recorded) {
            if (! in_array($recorded->value, $reasons, true)) {
                $reasons[] = $recorded->value;
            }
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

        $replayedDelta = $this->replayService->signedDelta(
            $item->product_id,
            $item->location_id,
            $item->variant_id,
            $asOf,
            $now,
            $item->final_qty_movement_marker,
        );

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
