<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\InventoryCountingItem;
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
        $item->final_qty = (string) $count1;
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
            $item->final_qty = (string) $theoretical;
            $item->resolution_method = ItemResolutionMethod::AutoAllMatch;
            $item->is_flagged = false;
            $item->resolved_at = now();
            $item->save();

            InventoryCountingEvent::recordAutoResolution($item, 'auto_all_match', bcadd((string) $theoretical, '0', 4));

            return;
        }

        // Case 2: Counters agree but differ from theoretical
        if ($this->floatsEqual($count1, $count2)) {
            $item->final_qty = (string) $count1;
            $item->resolution_method = ItemResolutionMethod::AutoCountersAgree;
            $item->is_flagged = true;
            $item->flag_reason = 'variance_from_theoretical';
            $item->resolved_at = now();
            $item->save();

            InventoryCountingEvent::recordAutoResolution($item, 'auto_counters_agree', bcadd((string) $count1, '0', 4));

            return;
        }

        // Case 3: Counters disagree - flag for third count or manual override
        $item->is_flagged = true;
        $item->flag_reason = 'counter_disagreement';

        // If one matches theoretical, note it
        if ($this->floatsEqual($count1, $theoretical) || $this->floatsEqual($count2, $theoretical)) {
            $item->flag_reason = 'counter_disagreement_one_matches_theoretical';
        }

        $item->save();
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
            $item->final_qty = (string) $majority;
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
