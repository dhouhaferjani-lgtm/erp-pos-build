<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Application\DTOs\ReplayPreviewDto;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\Services\OpeningCostGate;
use Illuminate\Support\Collection;

final class CountingReconciliationPayloadBuilder
{
    public function __construct(
        private readonly OpeningCostGate $openingCostGate,
        private readonly CountingReplayPreviewService $replayPreviewService,
    ) {}

    /**
     * @param  Collection<int, InventoryCountingItem>  $items
     * @return list<array<string, mixed>>
     */
    public function transformMany(Collection $items, ?InventoryCounting $counting = null): array
    {
        $gates = [];
        foreach ($items as $item) {
            $gates[$item->id] = $this->openingCostGate->evaluateItem(
                $item,
                (bool) $item->location->onboarding_mode,
            );
        }
        $previews = $counting === null ? [] : $this->replayPreviewService->forItems($counting, $items, $gates);
        $payload = [];

        foreach ($items as $item) {
            $payload[] = $this->buildPayload($item, $gates[$item->id], $previews[$item->id] ?? null);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(InventoryCountingItem $item, ?InventoryCounting $counting = null): array
    {
        $onboarding = (bool) $item->location->onboarding_mode;
        $gate = $this->openingCostGate->evaluateItem($item, $onboarding);
        $preview = $counting === null
            ? null
            : $this->replayPreviewService->forItem($counting, $item, $gate);

        return $this->buildPayload($item, $gate, $preview);
    }

    /**
     * @param  array{will_post_as_opening: bool, opening_cost_missing: bool}  $gate
     * @return array<string, mixed>
     */
    private function buildPayload(InventoryCountingItem $item, array $gate, ?ReplayPreviewDto $preview): array
    {

        return [
            'id' => $item->id,
            'product' => [
                'id' => $item->product->id,
                'name' => $item->product->name,
                'sku' => $item->product->sku,
                'quantity_decimals' => $item->product->unitOfMeasure->decimal_places ?? 4,
            ],
            'location' => [
                'code' => $item->location->code ?? null,
                'name' => $item->location->name,
            ],
            'theoretical_qty' => $item->theoretical_qty,
            'count_1' => $item->count_1_qty !== null ? [
                'qty' => $item->count_1_qty,
                'at' => $item->count_1_at?->toIso8601String(),
                'notes' => $item->count_1_notes,
            ] : null,
            'count_2' => $item->count_2_qty !== null ? [
                'qty' => $item->count_2_qty,
                'at' => $item->count_2_at?->toIso8601String(),
                'notes' => $item->count_2_notes,
            ] : null,
            'count_3' => $item->count_3_qty !== null ? [
                'qty' => $item->count_3_qty,
                'at' => $item->count_3_at?->toIso8601String(),
                'notes' => $item->count_3_notes,
            ] : null,
            'final_qty' => $item->final_qty,
            'variance' => $item->getVariance(),
            'resolution_method' => $item->resolution_method->value,
            'resolution_notes' => $item->resolution_notes,
            'is_flagged' => $item->is_flagged,
            'flag_reason' => $item->flag_reason,
            'expected_qty_at_apply' => $item->expected_qty_at_apply,
            'replay_audit' => $item->replay_audit,
            'replay_preview' => $preview?->toArray(),
            'flag_reasons' => $item->flag_reasons,
            'opening_unit_cost' => $item->opening_unit_cost,
            'will_post_as_opening' => $gate['will_post_as_opening'],
            'opening_cost_missing' => $gate['opening_cost_missing'],
        ];
    }
}
