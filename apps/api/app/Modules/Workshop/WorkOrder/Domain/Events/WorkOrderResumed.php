<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Canonical cross-plan lifecycle event. Emitted when a paused WorkOrder
 * is resumed (status Paused → InProgress). Plan C's
 * `ReopenTimeEntryOnWorkOrderResumed` listener subscribes.
 *
 * **Signature is locked** (see AutoERP Rule #8).
 */
final readonly class WorkOrderResumed
{
    public function __construct(
        public string $work_order_id,
        public \DateTimeImmutable $resumed_at,
    ) {}
}
