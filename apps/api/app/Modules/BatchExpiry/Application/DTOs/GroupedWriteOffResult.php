<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\DTOs;

/**
 * Outcome of a grouped write-off and the canonical shape of the `result` JSONB
 * column on grouped_write_offs.
 *
 * `replayed` is FALSE on the first application and TRUE when the result was
 * returned from a persisted idempotency record (no stock was touched). It is a
 * runtime-only signal and is intentionally NOT persisted: a stored record always
 * represents an already-applied write-off.
 */
final readonly class GroupedWriteOffResult
{
    /**
     * @param  list<GroupedWriteOffMovementResult>  $movements
     */
    public function __construct(
        public string $idempotencyKey,
        public bool $replayed,
        public array $movements,
    ) {}

    /**
     * Persisted payload (without `replayed`, which is a runtime flag).
     *
     * @return array{idempotency_key: string, movements: list<array{batch_id: int, movement_id: string, quantity: string, unit_cost: string, total_cost: string}>}
     */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'movements' => array_map(
                static fn (GroupedWriteOffMovementResult $m): array => $m->toArray(),
                $this->movements,
            ),
        ];
    }

    /**
     * Rebuild from a persisted (loosely typed) JSONB record. The reconstructed
     * result is always marked `replayed = true` because it can only originate from
     * an existing record. Each field is narrowed at the boundary.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;
        $rawMovements = $data['movements'] ?? [];

        $movements = [];
        if (is_array($rawMovements)) {
            foreach ($rawMovements as $movement) {
                if (is_array($movement)) {
                    $movements[] = GroupedWriteOffMovementResult::fromArray($movement);
                }
            }
        }

        return new self(
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : '',
            replayed: true,
            movements: $movements,
        );
    }
}
