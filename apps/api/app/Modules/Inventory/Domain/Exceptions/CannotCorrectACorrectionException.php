<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * A correction document cannot itself be corrected (DPA V7 / D8, the
 * ReverseWriteOffService contract). Partial corrections are forbidden in v1, so
 * a chain of contras has no representable meaning.
 */
class CannotCorrectACorrectionException extends DomainException
{
    public function __construct(
        public readonly string $adjustmentId,
        public readonly string $correctsAdjustmentId,
    ) {
        parent::__construct(
            "Stock adjustment {$adjustmentId} is itself a correction of {$correctsAdjustmentId} and cannot be corrected."
        );
    }
}
