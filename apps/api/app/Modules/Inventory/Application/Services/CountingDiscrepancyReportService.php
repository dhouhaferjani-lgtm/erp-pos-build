<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingAssignment;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CountingDiscrepancyReportService
{
    public function __construct(
        private readonly InventoryCountingService $countingService,
        private readonly CountingReconciliationPayloadBuilder $payloadBuilder,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly LateSyncResidualDetector $lateSyncResidualDetector,
    ) {}

    /**
     * @param  array<string, mixed>  $countingPayload
     * @return array<string, mixed>
     */
    public function build(InventoryCounting $counting, User $generatedBy, array $countingPayload): array
    {
        /** @var Collection<int, InventoryCountingItem> $items */
        $items = $this->countingService->getItemsForAdmin($counting);
        $counting->loadMissing(['company', 'assignments.user']);

        $currency = (string) ($counting->company->currency ?? 'TND');
        $moneyScale = $this->scaleResolver->getScale($currency);
        $appliedGrains = $this->appliedGrains($counting);
        $summary = $this->summary($counting, $items, $currency, $moneyScale, $appliedGrains);

        // Campaign W4-6 — every row carries the four numbers the operator came
        // for: what the system expected on the shelf, what was counted, the
        // difference, and whether that difference reached stock. `flagged_items`
        // alone was never enough: it held the real variances while the summary
        // said there were none.
        $reconciliationItems = array_map(
            fn (array $row, InventoryCountingItem $item): array => $row + $this->varianceColumns($item, $appliedGrains),
            $this->payloadBuilder->transformMany($items),
            $items->values()->all(),
        );

        return [
            'report_id' => Str::uuid()->toString(),
            'generated_at' => now()->toIso8601String(),
            'generated_by' => [
                'id' => $generatedBy->id,
                'name' => $generatedBy->name,
            ],
            'counting' => $countingPayload,
            'summary' => $summary,
            'items' => $reconciliationItems,
            'flagged_items' => array_values(array_filter(
                $reconciliationItems,
                static fn (array $item): bool => $item['is_flagged'] === true,
            )),
            'counter_performance' => $this->counterPerformance($counting, $items),
            'late_sync_residuals' => $this->lateSyncResidualDetector->forCounting($counting),
        ];
    }

    /**
     * @param  Collection<int, InventoryCountingItem>  $items
     * @param  array<string, true>  $appliedGrains
     * @return array<string, mixed>
     */
    private function summary(
        InventoryCounting $counting,
        Collection $items,
        string $currency,
        int $moneyScale,
        array $appliedGrains,
    ): array {
        $positive = CurrencyScale::bcformatStrict('0', $moneyScale);
        $negative = CurrencyScale::bcformatStrict('0', $moneyScale);
        $net = CurrencyScale::bcformatStrict('0', $moneyScale);
        $openingValue = CurrencyScale::bcformatStrict('0', $moneyScale);
        $openingItems = 0;
        $itemsNoVariance = 0;
        $itemsWithVariance = 0;
        $itemsApplied = 0;
        $itemsNotApplied = 0;

        $breakdown = [
            ItemResolutionMethod::AutoAllMatch->value => 0,
            ItemResolutionMethod::AutoCountersAgree->value => 0,
            ItemResolutionMethod::ThirdCountDecisive->value => 0,
            ItemResolutionMethod::ManualOverride->value => 0,
        ];

        foreach ($items as $item) {
            if ($item->resolution_method !== ItemResolutionMethod::Pending) {
                $breakdown[$item->resolution_method->value]++;
            }

            $varianceQty = $this->varianceQuantity($item);
            if (bccomp($varianceQty, '0', InventoryScale::QUANTITY_SCALE) === 0) {
                $itemsNoVariance++;
            } else {
                $itemsWithVariance++;

                if ($this->varianceWasApplied($item, $varianceQty, $appliedGrains)) {
                    $itemsApplied++;
                } else {
                    $itemsNotApplied++;
                }
            }

            $value = $this->varianceValue($varianceQty, $this->unitCostBasis($item), $moneyScale);
            $net = CurrencyScale::bcformatStrict(bcadd($net, $value, $moneyScale), $moneyScale);

            if (bccomp($value, '0', $moneyScale) > 0) {
                $positive = CurrencyScale::bcformatStrict(bcadd($positive, $value, $moneyScale), $moneyScale);
            }

            if (bccomp($value, '0', $moneyScale) < 0) {
                $negative = CurrencyScale::bcformatStrict(bcadd($negative, $value, $moneyScale), $moneyScale);
            }

            $payload = $this->payloadBuilder->transform($item);
            if ($payload['will_post_as_opening'] === true && $item->final_qty !== null) {
                $openingItems++;
                $openingLineValue = $this->varianceValue($item->final_qty, $this->unitCostBasis($item), $moneyScale);
                $openingValue = CurrencyScale::bcformatStrict(bcadd($openingValue, $openingLineValue, $moneyScale), $moneyScale);
            }
        }

        return [
            'total_items_counted' => $items->count(),
            'items_no_variance' => $itemsNoVariance,
            'items_with_variance' => $itemsWithVariance,
            'variance_breakdown' => $breakdown,
            'total_variance_value' => [
                'positive' => $positive,
                'negative' => $negative,
                'net' => $net,
                'currency' => $currency,
            ],
            'items_applied' => $itemsApplied,
            'items_not_applied' => $itemsNotApplied,
            'late_sales_corrections' => count($counting->late_sales_flags ?? []),
            'opening_items' => $openingItems,
            'opening_value' => $openingValue,
        ];
    }

    /**
     * The line's REAL variance: counted − expected-on-the-shelf.
     *
     * 🚨 Campaign W4-6. This used to subtract `expected_qty_at_apply`, which is
     * the replay TARGET (`final_qty + Σ movements after the count`) and is
     * therefore derived FROM `final_qty`: the subtraction collapsed to
     * `−replayed_delta` and read 0.0000 on every line that had not moved since
     * it was counted. The summary consequently reported `items_with_variance: 0`
     * while `flagged_items[]` carried a genuine two-unit shrinkage.
     *
     * On a replayed line the honest baseline is the on-hand quantity AS OF the
     * count instant — `on_hand_at_apply − replayed_delta` — so the variance
     * equals the adjustment the replay posted, and an in-count sale (which sits
     * in `replayed_delta`) contributes nothing to it. A legacy line has no
     * replay audit and keeps `final − theoretical`.
     *
     * @return numeric-string
     */
    private function varianceQuantity(InventoryCountingItem $item): string
    {
        /** @var numeric-string $finalQty */
        $finalQty = (string) ($item->final_qty ?? '0.0000');

        return bcsub($finalQty, $this->expectedQuantity($item), InventoryScale::QUANTITY_SCALE);
    }

    /**
     * What the system believed was on the shelf at the instant it was counted.
     *
     * @return numeric-string
     */
    private function expectedQuantity(InventoryCountingItem $item): string
    {
        $audit = $item->replay_audit;

        if (is_array($audit)
            && isset($audit['onHandAtApply'], $audit['replayedDelta'])
            && is_string($audit['onHandAtApply'])
            && is_string($audit['replayedDelta'])
        ) {
            /** @var numeric-string $onHandAtApply */
            $onHandAtApply = $audit['onHandAtApply'];
            /** @var numeric-string $replayedDelta */
            $replayedDelta = $audit['replayedDelta'];

            return bcsub($onHandAtApply, $replayedDelta, InventoryScale::QUANTITY_SCALE);
        }

        /** @var numeric-string $theoretical */
        $theoretical = (string) $item->theoretical_qty;

        return bcadd($theoretical, '0', InventoryScale::QUANTITY_SCALE);
    }

    /**
     * The expected / counted / variance / applied block appended to every
     * report row (W4-6).
     *
     * @param  array<string, true>  $appliedGrains
     * @return array<string, mixed>
     */
    private function varianceColumns(InventoryCountingItem $item, array $appliedGrains): array
    {
        $varianceQty = $this->varianceQuantity($item);
        $applied = $this->varianceWasApplied($item, $varianceQty, $appliedGrains);

        return [
            'expected_qty' => $this->expectedQuantity($item),
            'counted_qty' => $item->final_qty,
            'variance_qty' => $varianceQty,
            'variance_applied' => $applied,
            'not_applied_reason' => $applied ? null : $this->notAppliedReason($item),
        ];
    }

    /**
     * Whether this line's variance reached stock.
     *
     * A line that agrees with the shelf has nothing outstanding, so it counts as
     * applied. Otherwise the proof is a posted stock movement carrying this
     * counting as its source document — evidence, not the absence of a flag.
     *
     * @param  numeric-string  $varianceQty
     * @param  array<string, true>  $appliedGrains
     */
    private function varianceWasApplied(InventoryCountingItem $item, string $varianceQty, array $appliedGrains): bool
    {
        if (bccomp($varianceQty, '0', InventoryScale::QUANTITY_SCALE) === 0) {
            return true;
        }

        return isset($appliedGrains[$this->grainKey(
            (string) $item->product_id,
            (string) $item->location_id,
            $item->variant_id !== null ? (string) $item->variant_id : null,
        )]);
    }

    /** The first recorded reason that withheld this line's stock write. */
    private function notAppliedReason(InventoryCountingItem $item): ?string
    {
        foreach ($item->flag_reasons ?? [] as $reason) {
            $case = CountingItemFlagReason::tryFrom($reason);
            if ($case !== null && $case->blocksStockApplication()) {
                return $case->value;
            }
        }

        return null;
    }

    /**
     * Every stock grain this counting actually moved, in ONE query.
     *
     * Keyed on the movement's own source-document linkage, so it covers the
     * replay corrections, the legacy `COUNTING:{number}` corrections and the
     * onboarding opening movements alike.
     *
     * @return array<string, true>
     */
    private function appliedGrains(InventoryCounting $counting): array
    {
        $grains = [];

        $rows = StockMovement::query()
            ->where('reference_type', StockMovementReferenceType::InventoryCounting)
            ->where('reference_id', $counting->id)
            ->get(['product_id', 'location_id', 'variant_id']);

        foreach ($rows as $row) {
            $grains[$this->grainKey(
                (string) $row->product_id,
                (string) $row->location_id,
                $row->variant_id !== null ? (string) $row->variant_id : null,
            )] = true;
        }

        return $grains;
    }

    private function grainKey(string $productId, string $locationId, ?string $variantId): string
    {
        return $productId."\0".$locationId."\0".($variantId ?? '');
    }

    /**
     * @return numeric-string
     */
    private function unitCostBasis(InventoryCountingItem $item): string
    {
        if ($item->opening_unit_cost !== null) {
            /** @var numeric-string $openingUnitCost */
            $openingUnitCost = (string) $item->opening_unit_cost;

            return $openingUnitCost;
        }

        /** @var numeric-string $costPrice */
        $costPrice = (string) $item->product->cost_price;

        return $costPrice;
    }

    /**
     * @param  numeric-string  $varianceQty
     * @param  numeric-string  $unitCost
     * @return numeric-string
     */
    private function varianceValue(string $varianceQty, string $unitCost, int $moneyScale): string
    {
        $workingScale = $moneyScale + InventoryScale::QUANTITY_SCALE + 2;
        $rawValue = bcmul($varianceQty, $unitCost, $workingScale);

        return CurrencyScale::bcformatStrict($rawValue, $moneyScale);
    }

    /**
     * @param  Collection<int, InventoryCountingItem>  $items
     * @return list<array<string, mixed>>
     */
    private function counterPerformance(InventoryCounting $counting, Collection $items): array
    {
        /** @var Collection<int, InventoryCountingAssignment> $assignments */
        $assignments = $counting->assignments;

        $metrics = [];

        foreach ($assignments->sortBy('count_number') as $assignment) {
            $metrics[] = $this->counterMetrics($assignment, $items);
        }

        return $metrics;
    }

    /**
     * @param  Collection<int, InventoryCountingItem>  $items
     * @return array<string, mixed>
     */
    private function counterMetrics(InventoryCountingAssignment $assignment, Collection $items): array
    {
        $countNumber = $assignment->count_number;
        $countColumn = "count_{$countNumber}_qty";
        $itemsCounted = 0;
        $matchedOther = 0;
        $matchedTheoretical = 0;
        $provenWrongByThird = 0;

        foreach ($items as $item) {
            /** @var numeric-string|null $counterQty */
            $counterQty = $item->{$countColumn};
            if ($counterQty === null) {
                continue;
            }

            $itemsCounted++;
            if ($this->matchesAnyOtherCounter($item, $countNumber, $counterQty)) {
                $matchedOther++;
            }

            if (bccomp($counterQty, $this->baselineQuantity($item), InventoryScale::QUANTITY_SCALE) === 0) {
                $matchedTheoretical++;
            }

            $finalQty = $item->final_qty;
            if ($item->resolution_method === ItemResolutionMethod::ThirdCountDecisive
                && $finalQty !== null
                && bccomp($counterQty, $finalQty, InventoryScale::QUANTITY_SCALE) !== 0) {
                $provenWrongByThird++;
            }
        }

        return [
            'user' => [
                'id' => $assignment->user->id,
                'name' => $assignment->user->name,
            ],
            'items_counted' => $itemsCounted,
            'matched_other_counter' => $matchedOther,
            'matched_theoretical' => $matchedTheoretical,
            'times_proven_wrong_by_3rd' => $provenWrongByThird,
            'accuracy_rate' => $itemsCounted === 0 ? 0.0 : round(($matchedTheoretical / $itemsCounted) * 100, 1),
        ];
    }

    /**
     * @param  numeric-string  $counterQty
     */
    private function matchesAnyOtherCounter(InventoryCountingItem $item, int $countNumber, string $counterQty): bool
    {
        foreach ([1, 2, 3] as $otherCountNumber) {
            if ($otherCountNumber === $countNumber) {
                continue;
            }

            /** @var numeric-string|null $otherQty */
            $otherQty = $item->{"count_{$otherCountNumber}_qty"};
            if ($otherQty !== null && bccomp($counterQty, $otherQty, InventoryScale::QUANTITY_SCALE) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The counter-accuracy baseline — the same expected-at-count quantity the
     * variance is measured against (W4-6), so "matched theoretical" cannot
     * disagree with "no variance" on the same row.
     *
     * @return numeric-string
     */
    private function baselineQuantity(InventoryCountingItem $item): string
    {
        return $this->expectedQuantity($item);
    }
}
