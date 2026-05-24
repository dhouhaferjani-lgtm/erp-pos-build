<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by `ParseFailureResolutionService::resolve()` when the operator-
 * supplied corrected payload fails strict event-type schema validation
 * (the payload DTO's `fromArray()` rejects the structure, per the same
 * grammar `StrictCanonicalParser` enforces at ingest time — spec §7.6).
 *
 * The resolution transaction MUST NOT commit when this fires — the
 * quarantined `fiscal_events` row stays untouched, the operator can
 * correct the payload, and re-invoke the resolver. The previous-cause
 * exception (the DTO's typed throw) is exposed via `getPrevious()` so the
 * operator sees the specific schema violation.
 */
final class InvalidCorrectedPayloadException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
