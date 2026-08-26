<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * The "lost" device came back and closed its own shift, so its counted drawer
 * has REPLACED the figures an operator wrote when the shift was closed
 * administratively (LEDGER O-30, gate r1 finding 3).
 *
 * A NEW class, like {@see OrphanedShiftClosedByOperator} and for the same reason
 * (rule 8): this is neither a device close of an open shift (`shift.closed`) nor
 * an operator close (`shift.orphan_closed`). It is the correction of the second
 * by the first, and an auditor asking "which figures are in this row, and why
 * did they change?" can only be answered if that act has its own name.
 *
 * WHY IT EXISTS AT ALL. `pos:shift:close-orphaned` is used when a device is
 * believed gone. "Believed" is doing real work there: a till that was merely
 * offline — a dead battery, a week in a drawer, a shop that reopened — can sync
 * weeks later, and its `SESSION_CLOSE` carries a real counted drawer with a real
 * variance. The device is authoritative for the shift lifecycle; the operator's
 * derived, zero-variance pair was only ever a stand-in for a count nobody could
 * take. Before this event the projection no-oped on an already-CLOSED shift and
 * the device's real count was silently discarded, leaving the synthetic pair in
 * `pos_shifts` — and therefore in the NF525 JET's `FERMETURE_CAISSE`.
 *
 * Both sides of the swap are carried so the correction is legible without
 * re-reading the chain: `superseded*` is what the operator wrote, `device*` is
 * what replaced it.
 */
final class OrphanedShiftDeviceCloseApplied extends DomainEvent
{
    /**
     * @param  numeric-string|null  $supersededExpectedCash
     * @param  numeric-string|null  $supersededCountedCash
     * @param  numeric-string|null  $supersededVariance
     * @param  numeric-string|null  $deviceExpectedCash
     * @param  numeric-string|null  $deviceCountedCash
     * @param  numeric-string|null  $deviceVariance
     */
    public function __construct(
        public readonly string $shiftId,
        public readonly string $terminalId,
        public readonly string $companyId,
        public readonly string $fiscalEventId,
        public readonly string $operatorId,
        public readonly ?string $supersededExpectedCash,
        public readonly ?string $supersededCountedCash,
        public readonly ?string $supersededVariance,
        public readonly ?string $deviceExpectedCash,
        public readonly ?string $deviceCountedCash,
        public readonly ?string $deviceVariance,
        public readonly string $deviceClosedAt,
    ) {
        parent::__construct($shiftId);
    }

    public function getEventName(): string
    {
        return 'shift.orphan_device_close_applied';
    }
}
