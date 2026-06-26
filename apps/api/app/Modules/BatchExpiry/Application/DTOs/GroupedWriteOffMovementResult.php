<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\DTOs;

/**
 * Per-lot outcome of a grouped write-off: the stock_movements row id created for
 * the lot plus the cost snapshot persisted on that row (so an idempotent replay
 * returns the exact same movement ids and costs without re-reading the DB).
 */
final readonly class GroupedWriteOffMovementResult
{
    /**
     * @param  numeric-string  $quantity  Quantity written off for this lot (scale 4)
     * @param  numeric-string  $unitCost  Per-unit cost snapshot at COST_SCALE=6
     * @param  numeric-string  $totalCost  unitCost × quantity at COST_SCALE=6
     */
    public function __construct(
        public int $batchId,
        public string $movementId,
        public string $quantity,
        public string $unitCost,
        public string $totalCost,
    ) {}

    /**
     * @return array{batch_id: int, movement_id: string, quantity: string, unit_cost: string, total_cost: string}
     */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'movement_id' => $this->movementId,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
        ];
    }

    /**
     * Reconstruct from a persisted (loosely typed) JSONB row, narrowing each field
     * at the boundary so the rest of the domain works with a strict DTO.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var numeric-string $quantity */
        $quantity = self::numericString($data['quantity'] ?? null);
        /** @var numeric-string $unitCost */
        $unitCost = self::numericString($data['unit_cost'] ?? null);
        /** @var numeric-string $totalCost */
        $totalCost = self::numericString($data['total_cost'] ?? null);

        $batchId = $data['batch_id'] ?? null;
        $movementId = $data['movement_id'] ?? null;

        return new self(
            batchId: is_numeric($batchId) ? (int) $batchId : 0,
            movementId: is_string($movementId) ? $movementId : '',
            quantity: $quantity,
            unitCost: $unitCost,
            totalCost: $totalCost,
        );
    }

    /**
     * @return numeric-string
     */
    private static function numericString(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
