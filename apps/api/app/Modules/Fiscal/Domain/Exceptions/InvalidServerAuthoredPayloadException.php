<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by a server-authored company-integrity emitter (spec v7 §11.0)
 * when the constructed payload fails `FiscalPayloadConstraintValidator`
 * before the `fiscal_events` row is persisted.
 *
 * Server-authored events bypass the canonical-bytes parse path
 * (`StrictCanonicalParser`) by construction — the server owns the
 * serialization. The §11.0 invariant #3 still requires the same
 * per-event payload-shape gate (`validatePayloadKeySet` +
 * `validatePerEventConstraints`) to run BEFORE the persist, so the
 * server cannot drift away from what the parse path would accept.
 * A validator failure is a programming defect in the emitter (an extras
 * key, a malformed hash, a non-list sub-array) and MUST halt the persist
 * — the typed exception lets the caller surface the boundary error
 * without consuming the validator's internal RuntimeException string.
 *
 * The previous-cause exception (the validator's typed throw) is exposed
 * via `getPrevious()` so the operator/caller sees the specific shape
 * violation.
 */
final class InvalidServerAuthoredPayloadException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
