<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use RuntimeException;

/**
 * Thrown by FiscalEventPayloadRegistry when asked to resolve a fiscal-event
 * type whose payload DTO has not been implemented in this phase.
 *
 * The fiscal-event chain RESERVES the full event-type vocabulary at
 * Phase 1 (Appendix A) so the same `event_type` enum can extend across
 * phases without renumbering, but only a subset has payload handlers in
 * Phase 1. Calling FiscalEventEngine.append() with a reserved type must
 * surface this exception so the caller fails fast at the device boundary
 * rather than letting a half-typed payload land on the server.
 */
final class FiscalEventTypeNotImplemented extends RuntimeException
{
    public function __construct(public readonly FiscalEventType $type)
    {
        parent::__construct(
            "Fiscal event type {$type->value} has no Phase 1 payload handler; ".
            'see Appendix A for the reserved-not-implemented set.',
        );
    }
}
