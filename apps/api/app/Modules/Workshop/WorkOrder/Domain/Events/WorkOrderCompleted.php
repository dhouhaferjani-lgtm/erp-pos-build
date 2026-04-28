<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Canonical cross-plan lifecycle event. Emitted when work is finished
 * (status InProgress → Completed). Triggers:
 *  - Plan A's `WriteMileageReadingFromWorkOrderCompleted` listener.
 *  - Plan C's final TimeEntry close listener.
 *
 * `completion_mileage` is optional — null when the WO did not capture a
 * mileage reading (e.g. non-vehicle services).
 *
 * **Signature is locked** (see AutoERP Rule #8).
 */
final readonly class WorkOrderCompleted
{
    public function __construct(
        public string $work_order_id,
        public ?int $completion_mileage,
        public \DateTimeImmutable $completed_at,
    ) {}
}
