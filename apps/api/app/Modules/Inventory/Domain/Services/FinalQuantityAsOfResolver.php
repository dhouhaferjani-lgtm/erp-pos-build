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
                return $estimate;
            }
        }

        return null;
    }
}
