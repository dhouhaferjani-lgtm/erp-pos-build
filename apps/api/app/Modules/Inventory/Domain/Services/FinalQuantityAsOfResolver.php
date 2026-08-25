<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\InventoryScale;
use Carbon\CarbonInterface;

/** Resolve the replay boundary shared by preview and finalize. */
final class FinalQuantityAsOfResolver
{
    /**
     * The lowest-numbered count whose value equals final_qty supplies the
     * boundary. Manual overrides use their explicit resolution instant.
     */
    public function resolve(InventoryCountingItem $item): ?CarbonInterface
    {
        if ($item->resolution_method === ItemResolutionMethod::ManualOverride) {
            return $item->resolved_at;
        }

        $phase = $this->winningPhase($item);

        if ($phase === null) {
            return null;
        }

        /** @var CarbonInterface $estimate */
        $estimate = $item->{"count_{$phase}_at_estimate"};

        return $estimate;
    }

    /**
     * The movement-order marker of the SAME phase `resolve()` picked (W4-6 gate
     * r2, NEW-1). It breaks the same-second tie the timestamp cannot: a movement
     * whose id is at or below it was already on the line when the counter
     * reported. Null for a manual override (which has no counting phase of its
     * own) and for any line counted before the marker columns shipped — the
     * replay then falls back to pure timestamp semantics.
     */
    public function resolveMarker(InventoryCountingItem $item): ?string
    {
        if ($item->resolution_method === ItemResolutionMethod::ManualOverride) {
            return $item->final_qty_movement_marker;
        }

        $phase = $this->winningPhase($item);

        if ($phase === null) {
            return null;
        }

        $marker = $item->{"count_{$phase}_movement_marker"};

        return is_string($marker) ? $marker : null;
    }

    /**
     * The lowest-numbered count phase whose quantity equals `final_qty` AND
     * which carries a usable instant. Both `resolve()` and `resolveMarker()` go
     * through it so the boundary and its tie-break can never come from different
     * counts.
     */
    private function winningPhase(InventoryCountingItem $item): ?int
    {
        $finalQty = $item->final_qty;

        if ($finalQty === null) {
            return null;
        }

        foreach ([1, 2, 3] as $phase) {
            /** @var numeric-string|null $qty */
            $qty = $item->{"count_{$phase}_qty"};
            /** @var CarbonInterface|null $estimate */
            $estimate = $item->{"count_{$phase}_at_estimate"};

            if (
                $qty !== null
                && $estimate !== null
                && bccomp((string) $qty, (string) $finalQty, InventoryScale::QUANTITY_SCALE) === 0
            ) {
                return $phase;
            }
        }

        return null;
    }
}
