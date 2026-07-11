<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use DomainException;

/**
 * Thrown when a replayed MovementIntent collides on idempotency_key with an
 * already-recorded movement whose semantic fields (repository, direction,
 * amount, currency, source) DISAGREE with the replay.
 *
 * A genuine idempotent replay (all semantic fields match) returns the existing
 * movement instead. This exception surfaces a real conflict — the same key was
 * reused for a materially different movement — and must fail loudly.
 *
 * Extends \DomainException so bootstrap/app.php maps it to HTTP 422
 * BUSINESS_ERROR rather than a 500.
 */
final class IdempotencyConflictException extends DomainException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $detail,
    ) {
        parent::__construct(
            "Idempotency key {$idempotencyKey} already exists for a different movement: {$detail}.",
        );
    }
}
