<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Canonical cross-plan lifecycle event. Emitted when work is halted
 * (status InProgress → Paused). Triggers Plan C's TimeEntry close listener.
 *
 * **Signature is locked** (see AutoERP Rule #8).
 */
final readonly class WorkOrderPaused
{
    public function __construct(
        public string $work_order_id,
        public string $reason_code,
        public \DateTimeImmutable $paused_at,
    ) {}
}
