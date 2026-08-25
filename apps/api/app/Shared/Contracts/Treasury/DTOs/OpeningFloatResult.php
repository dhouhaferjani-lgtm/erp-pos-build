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
     * @param  string  $balanceAfter  repository balance immediately after the float landed. Always a
     *                                 numeric-string in practice (it is bcmath output from the movement
     *                                 port), declared `string` to mirror MovementResult::$balanceAfter
     *                                 exactly — narrowing here without narrowing there would only move
     *                                 the cast to the boundary between two values that are already the
     *                                 same value.
     * @param  bool  $wasIdempotentHit  true when the float was already recorded and nothing was written
     */
    public function __construct(
        public string $movementId,
        public string $balanceAfter,
        public bool $wasIdempotentHit,
    ) {}
}
