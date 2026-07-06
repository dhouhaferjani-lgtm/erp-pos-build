<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Domain\Services\MovementReplayService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Service for reconciling inventory counting items.
 */
class CountingReconciliationService
{
    private const EPSILON = 0.0001;

    private const VARIANCE_THRESHOLD_MINOR = 0.02; // 2%

    private const VARIANCE_THRESHOLD_SIGNIFICANT = 0.05; // 5%

    private const VARIANCE_THRESHOLD_CRITICAL = 0.10; // 10%

    /**
     * Default-constructed (zero dependencies of its own) so existing
     * no-arg `new CountingReconciliationService()` call sites keep working;
     * the Laravel container still resolves a real instance for it when
     * this service is built via DI.
     */
    public function __construct(
        private readonly MovementReplayService $replayService = new MovementReplayService,
    ) {}

    /**
     * Run reconciliation for all items in a counting.
     */
    public function runReconciliation(InventoryCounting $counting): void
    {
        DB::transaction(function () use ($counting): void {
            foreach ($counting->items as $item) {
                $this->reconcileItem($item, $counting->requires_count_3);
            }
        });
    }

    /**
     * Reconcile a single item.
     */
    public function reconcileItem(InventoryCountingItem $item, bool $hasThirdCount = false): void
    {
        // Skip if already resolved
        if ($item->resolution_method !== ItemResolutionMethod::Pending) {
            return;
        }

        $count1 = $item->count_1_qty !== null ? (float) $item->count_1_qty : null;
        $count2 = $item->count_2_qty !== null ? (float) $item->count_2_qty : null;
        $count3 = $item->count_3_qty !== null ? (float) $item->count_3_qty : null;
        $theoretical = (float) $item->theoretical_qty;

        // Single count mode
        if ($count1 !== null && $count2 === null) {
            $this->resolveSingleCount($item, $count1, $theoretical);

            return;
        }

        // Double count mode
        if ($count1 !== null && $count2 !== null && $count3 === null) {
            $this->resolveDoubleCount($item, $count1, $count2, $theoretical, $hasThirdCount);

            return;
        }

        // Triple count mode
        if ($count1 !== null && $count2 !== null && $count3 !== null) {
            $this->resolveTripleCount($item, $count1, $count2, $count3, $theoretical);
        }
    }

    /**
     * Resolve single count.
     */
    private function resolveSingleCount(
        InventoryCountingItem $item,
        float $count1,
        float $theoretical
    ): void {
        // final_qty column write uses the same scale-4 bcadd normalization as
        // the event payload (below) so the column and the JSONB event agree.
        $item->final_qty = bcadd((string) $count1, '0', 4);
        $item->resolved_at = now();

        if ($this->floatsEqual($count1, $theoretical)) {
            $item->resolution_method = ItemResolutionMethod::AutoAllMatch;
            $item->is_flagged = false;
        } else {
            $item->resolution_method = ItemResolutionMethod::AutoCountersAgree;
            $item->is_flagged = true;
            $item->flag_reason = $this->getVarianceFlagReason($count1, $theoretical);
        }

        $item->save();

        InventoryCountingEvent::recordAutoResolution(
            $item,
            $item->resolution_method->value,
            bcadd((string) $count1, '0', 4)
        );
    }

    /**
     * Resolve double count.
     */
    private function resolveDoubleCount(
        InventoryCountingItem $item,
        float $count1,
        float $count2,
        float $theoretical,
        bool $hasThirdCount
    ): void {
        // Case 1: Both counts match theoretical
        if ($this->floatsEqual($count1, $theoretical) && $this->floatsEqual($count2, $theoretical)) {
            $item->final_qty = bcadd((string) $theoretical, '0', 4);
            $item->resolution_method = ItemResolutionMethod::AutoAllMatch;
            $item->is_flagged = false;
            $item->resolved_at = now();
            $item->save();

            InventoryCountingEvent::recordAutoResolution($item, 'auto_all_match', bcadd((string) $theoretical, '0', 4));

            return;
        }

        // Case 2: Counters agree but differ from theoretical
        if ($this->floatsEqual($count1, $count2)) {
            $item->final_qty = bcadd((string) $count1, '0', 4);
            $item->resolution_method = ItemResolutionMethod::AutoCountersAgree;
            $item->is_flagged = true;
            $item->flag_reason = 'variance_from_theoretical';
            $item->resolved_at = now();
            $item->save();

            InventoryCountingEvent::recordAutoResolution($item, 'auto_counters_agree', bcadd((string) $count1, '0', 4));

            return;
        }

        // Case 3: Raw counters disagree. Before giving up to a third
        // count/manual override, normalize both submitted counts to the
        // later of the two count instants (replaying sales/movements that
        // happened between them) — a raw disagreement can be a genuine
        // agreement once the count instants are aligned.
        if ($this->resolveByNormalizedAgreement($item, $theoretical)) {
            return;
        }

        $item->is_flagged = true;
        $item->flag_reason = 'counter_disagreement';

        // If one matches theoretical, note it
        if ($this->floatsEqual($count1, $theoretical) || $this->floatsEqual($count2, $theoretical)) {
            $item->flag_reason = 'counter_disagreement_one_matches_theoretical';
        }

        $item->save();
    }

    /**
     * Attempt to resolve a raw counter disagreement by normalizing both
     * counts to a common instant (the later of the two `count_N_at_estimate`
     * timestamps) via signed movement replay. Only applicable when BOTH
     * counts carry an estimate timestamp (B2) — legacy items (estimate
     * columns null) fall through untouched to the existing raw-comparison
     * path, per the normative rule.
     *
     * On normalized agreement, resolves the item exactly like the raw
     * cases above (final_qty = normalized value; AutoAllMatch vs
     * AutoCountersAgree decided against theoretical) and appends the
     * INFORMATIONAL `normalized_agreement` flag reason to `flag_reasons` —
     * this never itself sets `is_flagged`; `is_flagged` here still comes
     * from the theoretical-variance check, same as the raw AutoCountersAgree
     * case.
     *
     * Returns false (no mutation) when estimates are missing or the
     * normalized values still disagree, leaving the caller to apply the
     * unchanged "genuine mismatch" behavior.
     */
    private function resolveByNormalizedAgreement(InventoryCountingItem $item, float $theoretical): bool
    {
        $estimate1 = $item->count_1_at_estimate;
        $estimate2 = $item->count_2_at_estimate;

        if ($estimate1 === null || $estimate2 === null) {
            return false;
        }

        $latest = $estimate1->greaterThan($estimate2) ? $estimate1 : $estimate2;

        $normalized1 = $this->normalizeCount((string) $item->count_1_qty, $item, $estimate1, $latest);
        $normalized2 = $this->normalizeCount((string) $item->count_2_qty, $item, $estimate2, $latest);

        if (! $this->floatsEqual((float) $normalized1, (float) $normalized2)) {
            return false;
        }

        $normalizedValue = (float) $normalized1;
        $item->final_qty = bcadd($normalized1, '0', InventoryScale::QUANTITY_SCALE);
        $item->resolved_at = now();

        if ($this->floatsEqual($normalizedValue, $theoretical)) {
            $item->resolution_method = ItemResolutionMethod::AutoAllMatch;
            $item->is_flagged = false;
        } else {
            $item->resolution_method = ItemResolutionMethod::AutoCountersAgree;
            $item->is_flagged = true;
            $item->flag_reason = $this->getVarianceFlagReason($normalizedValue, $theoretical);
        }

        $this->appendFlagReason($item, CountingItemFlagReason::NormalizedAgreement);

        $item->save();

        InventoryCountingEvent::recordAutoResolution(
            $item,
            $item->resolution_method->value,
            $normalized1
        );

        return true;
    }

    /**
     * Replay signed stock movements between this count's own instant and
     * the common `$to` instant, and add the result to the raw submitted
     * quantity — this is `normalized_N` from the normative rule.
     */
    private function normalizeCount(
        string $countQty,
        InventoryCountingItem $item,
        CarbonInterface $from,
        CarbonInterface $to,
    ): string {
        $delta = $this->replayService->signedDelta(
            $item->product_id,
            $item->location_id,
            $item->variant_id,
            $from,
            $to,
        );

        return bcadd($countQty, $delta, InventoryScale::QUANTITY_SCALE);
    }

    /**
     * Append a flag reason to the `flag_reasons` jsonb array, idempotently.
     * Deliberately does NOT touch `is_flagged` — callers decide that
     * separately (per `CountingItemFlagReason::isBlocking()` semantics).
     */
    private function appendFlagReason(InventoryCountingItem $item, CountingItemFlagReason $reason): void
    {
        $reasons = $item->flag_reasons ?? [];

        if (! in_array($reason->value, $reasons, true)) {
            $reasons[] = $reason->value;
        }

        $item->flag_reasons = $reasons;
    }

    /**
     * Resolve triple count.
     */
    private function resolveTripleCount(
        InventoryCountingItem $item,
        float $count1,
        float $count2,
        float $count3,
        float $theoretical
    ): void {
        // Find majority (2 of 3 agree)
        $counts = [$count1, $count2, $count3];
        $majority = $this->findMajority($counts);

        if ($majority !== null) {
            $item->final_qty = bcadd((string) $majority, '0', 4);
            $item->resolution_method = ItemResolutionMethod::ThirdCountDecisive;
            $item->resolved_at = now();

            // Determine which counter was wrong
            $wrongCounter = $this->identifyWrongCounter($count1, $count2, $count3, $majority);

            $item->is_flagged = true;
            $item->flag_reason = $this->floatsEqual($majority, $theoretical)
                ? "counter_{$wrongCounter}_proven_wrong"
                : 'variance_confirmed_by_third_count';

            $item->save();

            InventoryCountingEvent::recordAutoResolution($item, 'third_count_decisive', bcadd((string) $majority, '0', 4));

            return;
        }

        // All three counts differ - requires manual override
        $item->is_flagged = true;
        $item->flag_reason = 'no_consensus';
        $item->save();
    }

    /**
     * Find majority value (2 of 3 must match).
     *
     * @param  array<float>  $counts
     */
    private function findMajority(array $counts): ?float
    {
        if ($this->floatsEqual($counts[0], $counts[1])) {
            return $counts[0];
        }
        if ($this->floatsEqual($counts[0], $counts[2])) {
            return $counts[0];
        }
        if ($this->floatsEqual($counts[1], $counts[2])) {
            return $counts[1];
        }

        return null;
    }

    /**
     * Identify which counter was wrong.
     */
    private function identifyWrongCounter(
        float $count1,
        float $count2,
        float $count3,
        float $majority
    ): int {
        if (! $this->floatsEqual($count1, $majority)) {
            return 1;
        }
        if (! $this->floatsEqual($count2, $majority)) {
            return 2;
        }

        return 3;
    }

    /**
     * Get flag reason based on variance.
     */
    private function getVarianceFlagReason(float $counted, float $theoretical): string
    {
        if ($theoretical === 0.0) {
            return 'variance_from_zero_theoretical';
        }

        $variancePercent = abs(($counted - $theoretical) / $theoretical);

        if ($variancePercent >= self::VARIANCE_THRESHOLD_CRITICAL) {
            return 'critical_variance';
        }
        if ($variancePercent >= self::VARIANCE_THRESHOLD_SIGNIFICANT) {
            return 'significant_variance';
        }
        if ($variancePercent >= self::VARIANCE_THRESHOLD_MINOR) {
            return 'minor_variance';
        }

        return 'variance_from_theoretical';
    }

    /**
     * Compare floats with epsilon tolerance.
     */
    private function floatsEqual(float $a, float $b): bool
    {
        return abs($a - $b) < self::EPSILON;
    }
}
