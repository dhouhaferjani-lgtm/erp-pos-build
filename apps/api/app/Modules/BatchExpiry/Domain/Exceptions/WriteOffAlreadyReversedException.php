<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Exceptions;

use App\Modules\Inventory\Domain\StockMovement;

/**
 * Thrown when a write-off movement is reversed more than once.
 *
 * A write-off can be reversed at most once. The first reversal creates an inverse
 * stock_movements row whose `reverses_movement_id` points back at the original;
 * any further attempt is rejected. This is enforced at two layers:
 *  - App layer: the {@see StockMovement::reversalOf()}
 *    relation is checked before creating the inverse.
 *  - DB layer (PostgreSQL): the partial unique index
 *    `stock_movements_reverses_movement_id_unique`
 *    (WHERE reverses_movement_id IS NOT NULL) is the race-safe backstop; a unique
 *    violation is translated into this exception.
 *
 * Maps to an HTTP 409 Conflict at the presentation boundary.
 */
final class WriteOffAlreadyReversedException extends \DomainException
{
    public static function forMovement(string $movementId): self
    {
        return new self("Write-off movement {$movementId} has already been reversed.");
    }
}
