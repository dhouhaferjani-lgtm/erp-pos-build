<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockTransferLineBatchAllocation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One shipped lot of one transfer line, plus its four T-2 receipt counters and
 * the derived per-lot remainder.
 *
 * `expiry_status` is the STRING value of BatchExpiry's ExpiryStatus enum, not the
 * enum type: today's controller already emits `$allocation->batch->expiryStatus()->value`
 * (apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:329),
 * and typing the member as the foreign enum would import a BatchExpiry symbol into an
 * Inventory DTO for no wire benefit. The emitted JSON is byte-identical to today's.
 */
#[TypeScript]
final class StockTransferLineBatchAllocationData extends Data
{
    /**
     * @param  numeric-string  $quantity  the shipped allocation
     * @param  numeric-string  $quantity_received
     * @param  numeric-string  $quantity_damaged
     * @param  numeric-string  $quantity_written_off
     * @param  numeric-string  $quantity_returned
     * @param  numeric-string  $quantity_remaining  quantity − received − damaged − written_off − returned, at 4 dp
     */
    public function __construct(
        public readonly string $id,
        public readonly int $batch_id,
        public readonly string $batch_number,
        public readonly ?string $expiry_date,
        public readonly string $expiry_status,
        public readonly bool $can_be_sold,
        public readonly string $quantity,
        public readonly string $quantity_received,
        public readonly string $quantity_damaged,
        public readonly string $quantity_written_off,
        public readonly string $quantity_returned,
        public readonly string $quantity_remaining,
    ) {}

    public static function fromModel(StockTransferLineBatchAllocation $allocation): self
    {
        return new self(
            id: (string) $allocation->id,
            batch_id: (int) $allocation->batch_id,
            batch_number: (string) $allocation->batch->batch_number,
            expiry_date: $allocation->batch->expiry_date?->toDateString(),
            expiry_status: $allocation->batch->expiryStatus()->value,
            can_be_sold: $allocation->batch->canBeSold(),
            quantity: (string) $allocation->quantity,
            quantity_received: (string) $allocation->quantity_received,
            quantity_damaged: (string) $allocation->quantity_damaged,
            quantity_written_off: (string) $allocation->quantity_written_off,
            quantity_returned: (string) $allocation->quantity_returned,
            quantity_remaining: $allocation->remainingQuantity(),
        );
    }
}
