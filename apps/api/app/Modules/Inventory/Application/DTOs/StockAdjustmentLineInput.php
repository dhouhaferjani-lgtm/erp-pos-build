<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\MovementReason;

/**
 * One authored correction line, as it arrives from the HTTP boundary.
 *
 * The lot is identified by its PUBLIC uuid (`product_batches.uuid`) — the
 * identifier the house already exposes at `POST /batches/{uuid}/write-off` —
 * and resolved to the int `batch_id` inside the service (DPA V7 / D1b part 1).
 */
final class StockAdjustmentLineInput
{
    /**
     * @param  numeric-string  $deltaQuantity  SIGNED, non-zero
     * @param  numeric-string  $observedBefore  The operator's authoring snapshot
     */
    public function __construct(
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly ?string $batchUuid,
        public readonly MovementReason $reasonCode,
        public readonly string $deltaQuantity,
        public readonly string $observedBefore,
        public readonly ?string $lineNote = null,
    ) {}
}
