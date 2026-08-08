<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * The document already carries a correction (DPA V7 / D8). Correcting is
 * all-or-nothing, so the document-level guard and the movement-level
 * `reverses_movement_id` partial unique cannot disagree.
 */
class AdjustmentAlreadyCorrectedException extends DomainException
{
    public function __construct(
        public readonly string $adjustmentId,
        public readonly string $correctionId,
    ) {
        parent::__construct("Stock adjustment {$adjustmentId} was already corrected by {$correctionId}.");
    }
}
