<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Canonical cross-plan lifecycle event. Emitted when the WorkOrder is
 * cancelled (any cancellable state → Cancelled). Triggers release of any
 * outstanding stock reservations via `InventoryReservationServiceInterface`.
 *
 * `reason_code` is the scalar value of a
 * `App\Modules\Workshop\WorkOrder\Domain\Enums\CancellationReason` — kept as
 * a string on the wire so downstream subscribers can evolve independently.
 *
 * **Signature is locked** (see AutoERP Rule #8).
 */
final readonly class WorkOrderCancelled
{
    public function __construct(
        public string $work_order_id,
        public string $reason_code,
        public \DateTimeImmutable $cancelled_at,
    ) {}
}
