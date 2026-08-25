<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\DTOs;

/**
 * Result of seeding a repository opening float (W4-2).
 */
final readonly class OpeningFloatResult
{
    /**
     * @param  string  $movementId  UUID of the written (or idempotently matched) opening movement
     * @param  numeric-string  $balanceAfter  repository balance immediately after the float landed
     * @param  bool  $wasIdempotentHit  true when the float was already recorded and nothing was written
     */
    public function __construct(
        public string $movementId,
        public string $balanceAfter,
        public bool $wasIdempotentHit,
    ) {}
}
