<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

final class ProjectionInvariantViolationException extends RuntimeException
{
    public function __construct(
        public readonly string $projectorName,
        public readonly string $fiscalEventId,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Projector "%s" cannot apply fiscal_event %s: %s',
            $projectorName,
            $fiscalEventId,
            $reason,
        ));
    }
}
