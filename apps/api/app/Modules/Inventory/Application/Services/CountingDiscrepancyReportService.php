<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingAssignment;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CountingDiscrepancyReportService
{
    public function __construct(
        private readonly InventoryCountingService $countingService,
        private readonly CountingReconciliationPayloadBuilder $payloadBuilder,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
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
        $summary = $this->summary($counting, $items, $currency, $moneyScale);
        $reconciliationItems = $this->payloadBuilder->transformMany($items);

        return [
            'report_id' => Str::uuid()->toString(),
            'generated_at' => now()->toIso8601String(),
            'generated_by' => [
                'id' => $generatedBy->id,
                'name' => $generatedBy->name,
            ],
            'counting' => $countingPayload,
            'summary' => $summary,
            'flagged_items' => array_values(array_filter(
                $reconciliationItems,
                static fn (array $item): bool => $item['is_flagged'] === true,
            )),
            'counter_performance' => $this->counterPerformance($counting, $items),
        ];
    }

    /**
     * @param  Collection<int, InventoryCountingItem>  $items
     * @return array<string, mixed>
     */
    private function summary(InventoryCounting $counting, Collection $items, string $currency, int $moneyScale): array
    {
        $positive = CurrencyScale::bcformatStrict('0', $moneyScale);
        $negative = CurrencyScale::bcformatStrict('0', $moneyScale);
        $net = CurrencyScale::bcformatStrict('0', $moneyScale);
        $openingValue = CurrencyScale::bcformatStrict('0', $moneyScale);
        $openingItems = 0;
        $itemsNoVariance = 0;
        $itemsWithVariance = 0;

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
            'late_sales_corrections' => count($counting->late_sales_flags ?? []),
            'opening_items' => $openingItems,
            'opening_value' => $openingValue,
        ];
    }

    /**
     * @return numeric-string
     */
    private function varianceQuantity(InventoryCountingItem $item): string
    {
        /** @var numeric-string $finalQty */
        $finalQty = (string) ($item->final_qty ?? '0.0000');
        /** @var numeric-string $baseline */
        $baseline = (string) ($item->expected_qty_at_apply ?? $item->theoretical_qty);

        return bcsub($finalQty, $baseline, InventoryScale::QUANTITY_SCALE);
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
     * @return numeric-string
     */
    private function baselineQuantity(InventoryCountingItem $item): string
    {
        /** @var numeric-string $baseline */
        $baseline = (string) ($item->expected_qty_at_apply ?? $item->theoretical_qty);

        return $baseline;
    }
}
