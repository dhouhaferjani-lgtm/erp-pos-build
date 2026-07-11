<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

/**
 * Result of recording one movement leg via the write port's `record()` method (Task 11).
 */
final readonly class MovementResult
{
    /**
     * @param  string  $movementId  UUID of the written (or idempotently matched) RepositoryMovement row
     * @param  string  $balanceAfter  numeric-string, repository balance immediately after this movement
     * @param  int  $ordinal  Monotonic per-repository sequence number of this movement
     * @param  bool  $wasIdempotentHit  True when the idempotency key already existed and no new row was written
     */
    public function __construct(
        public string $movementId,
        public string $balanceAfter,
        public int $ordinal,
        public bool $wasIdempotentHit,
    ) {}
}
