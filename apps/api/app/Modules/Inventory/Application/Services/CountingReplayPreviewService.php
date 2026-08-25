<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Application\DTOs\ReplayPreviewDto;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ReplayPreviewMode;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Domain\Services\CountingReplayGuardEvaluator;
use App\Modules\Inventory\Domain\Services\FinalQuantityAsOfResolver;
use App\Modules\Inventory\Domain\Services\MovementReplayService;
use App\Modules\Inventory\Domain\Services\OpeningCostGate;
use App\Modules\Inventory\Domain\Services\ReplayPreviewInput;
use App\Modules\Inventory\Domain\StockLevel;
use Illuminate\Support\Collection;

final class CountingReplayPreviewService
{
    public function __construct(
        private readonly MovementReplayService $replayService,
        private readonly FinalQuantityAsOfResolver $asOfResolver,
        private readonly OpeningCostGate $openingCostGate,
        private readonly CountingReplayGuardEvaluator $guardEvaluator,
    ) {}

    /** @param array{will_post_as_opening: bool, opening_cost_missing: bool}|null $openingGate */
    public function forItem(
        InventoryCounting $counting,
        InventoryCountingItem $item,
        ?array $openingGate = null,
    ): ?ReplayPreviewDto {
        $item->loadMissing(['product.unitOfMeasure', 'location']);
        $gate = $openingGate ?? $this->openingCostGate->evaluateItem(
            $item,
            (bool) $item->location->onboarding_mode,
        );

        return $this->forItems($counting, collect([$item]), [$item->id => $gate])[$item->id] ?? null;
    }

    /**
     * @param  Collection<int, InventoryCountingItem>  $items
     * @param  array<string, array{will_post_as_opening: bool, opening_cost_missing: bool}>  $openingGates
     * @return array<string, ReplayPreviewDto|null>
     */
    public function forItems(InventoryCounting $counting, Collection $items, array $openingGates): array
    {
        if ($counting->status !== CountingStatus::PendingReview) {
            return [];
        }

        $eligible = $items->filter(fn (InventoryCountingItem $item): bool => $item->final_qty !== null);
        if ($eligible->isEmpty()) {
            return [];
        }

        $stockByGrain = $this->stockByGrain($counting, $eligible);
        $observedAt = now();
        $inputs = [];
        $asOfByItem = [];
        foreach ($eligible as $item) {
            $asOf = $item->final_qty_as_of ?? $this->asOfResolver->resolve($item);
            if ($asOf === null) {
                continue;
            }
            $asOfByItem[$item->id] = $asOf;
            $inputs[] = new ReplayPreviewInput(
                key: $item->id,
                productId: $item->product_id,
                locationId: $item->location_id,
                variantId: $item->variant_id,
                finalQty: $this->quantity((string) $item->final_qty),
                onHandNow: $stockByGrain[$this->grainKey($item->product_id, $item->location_id, $item->variant_id)] ?? '0.0000',
                from: $asOf,
                windowMinutes: $counting->ambiguity_window_minutes,
                marker: $item->final_qty_movement_marker ?? $this->asOfResolver->resolveMarker($item),
            );
        }

        $batch = $this->replayService->computeMany($inputs, $observedAt, $counting->company_id);
        $previews = [];
        foreach ($eligible as $item) {
            $onHand = $stockByGrain[$this->grainKey($item->product_id, $item->location_id, $item->variant_id)] ?? '0.0000';
            if (! isset($asOfByItem[$item->id])) {
                /** @var numeric-string $adjustment */
                $adjustment = bcsub(
                    $this->quantity((string) $item->final_qty),
                    $this->quantity((string) $item->theoretical_qty),
                    InventoryScale::QUANTITY_SCALE,
                );
                $previews[$item->id] = new ReplayPreviewDto(
                    mode: ReplayPreviewMode::LegacyDelta,
                    movementsSinceCount: null,
                    expectedNow: bcadd($onHand, $adjustment, InventoryScale::QUANTITY_SCALE),
                    adjustment: $adjustment,
                    willAutoPost: true,
                    blockedReason: null,
                );

                continue;
            }

            $result = $batch[$item->id];
            $onboarding = (bool) $item->location->onboarding_mode;
            $gate = $openingGates[$item->id];
            // W4-6: only a reason that answers blocksStockApplication() stops the
            // auto-post. `basket_window` is recorded on the item at apply time
            // but the correction posts, so the preview must not promise a skip.
            $blocked = $this->guardEvaluator->preApplyBlocker($result->hasMovementNear, $gate['opening_cost_missing'])
                ?? $this->guardEvaluator->atApply($onboarding, $result->computation->expectedNow);
            $previews[$item->id] = new ReplayPreviewDto(
                mode: ReplayPreviewMode::TimestampReplay,
                movementsSinceCount: $result->computation->movementsSinceCount,
                expectedNow: $result->computation->expectedNow,
                adjustment: $result->computation->adjustment,
                willAutoPost: $blocked === null,
                blockedReason: $blocked?->value,
            );
        }

        return $previews;
    }

    /**
     * @param  Collection<int, InventoryCountingItem>  $items
     * @return array<string, numeric-string>
     */
    private function stockByGrain(InventoryCounting $counting, Collection $items): array
    {
        $rows = StockLevel::query()
            ->where('company_id', $counting->company_id)
            ->whereIn('product_id', $items->pluck('product_id')->unique()->values())
            ->whereIn('location_id', $items->pluck('location_id')->unique()->values())
            ->get(['product_id', 'location_id', 'variant_id', 'quantity']);

        $stock = [];
        foreach ($rows as $row) {
            $stock[$this->grainKey($row->product_id, $row->location_id, $row->variant_id)] = bcadd(
                (string) $row->quantity,
                '0',
                InventoryScale::QUANTITY_SCALE,
            );
        }

        return $stock;
    }

    private function grainKey(string $productId, string $locationId, ?string $variantId): string
    {
        return $productId."\0".$locationId."\0".($variantId ?? '');
    }

    /**
     * @param  ''|numeric-string  $value
     * @return numeric-string
     */
    private function quantity(string $value): string
    {
        return $value === ''
            ? '0.0000'
            : bcadd($value, '0', InventoryScale::QUANTITY_SCALE);
    }
}
