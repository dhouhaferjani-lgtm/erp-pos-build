<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Canonical cross-plan lifecycle event. Emitted when the primary technician
 * starts active work on the WorkOrder (status Approved|Paused → InProgress).
 *
 * **Signature is locked.** Plan C's `OpenTimeEntryOnWorkOrderStarted` listener
 * subscribes. Do NOT rename, reorder or remove these parameters without also
 * updating every subscriber across all plans — per AutoERP Rule #8 (events
 * are immutable forever; create a versioned replacement instead).
 */
final readonly class WorkOrderStarted
{
    public function __construct(
        public string $work_order_id,
        public string $primary_technician_profile_id,
        public \DateTimeImmutable $started_at,
    ) {}
}
