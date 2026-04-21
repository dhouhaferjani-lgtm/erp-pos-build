<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Canonical cross-plan lifecycle event. Emitted explicitly when the WorkOrder
 * transitions from Invoiced → Closed (payment confirmed + vehicle returned).
 * Plan D's `MirrorAppointmentOnWorkOrderClosed` listener subscribes — Plan D
 * MUST NOT infer closure from invoice-paid state.
 *
 * **Signature is locked** (see AutoERP Rule #8).
 */
final readonly class WorkOrderClosed
{
    public function __construct(
        public string $work_order_id,
        public \DateTimeImmutable $closed_at,
    ) {}
}
