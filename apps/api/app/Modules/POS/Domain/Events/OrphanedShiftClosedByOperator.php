<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when an operator closes a `pos_shifts` row that a FORCED terminal
 * release orphaned (LEDGER O-30 / Q7-OWES-1), via
 * `pos:shift:close-orphaned`.
 *
 * A NEW event class, deliberately — {@see ShiftClosed} is the DEVICE's closure
 * fact and rule 8 forbids restructuring it. The distinction is not cosmetic:
 * `shift.closed` is what an auditor reads as "the cashier counted the drawer
 * and the till closed itself"; this act is the opposite — nobody counted,
 * because the device is gone. Reusing `ShiftClosed` would forge that.
 *
 * AUDIT REGISTER ONLY — NOT the NF525 JET export. `Nf525DataProvider` whitelists
 * three terminal event types and derives its shift events from `pos_shifts`
 * rows, so this event lands in `audit_events` and nowhere else. Note the
 * consequence that IS unavoidable: once the row carries a `closed_at`, the JET's
 * `EvenementsTechniques` section gains a `FERMETURE_CAISSE` for it
 * (`Nf525XmlBuilder::addTechnicalEvents()`), and the JET schema has no field for
 * "closed administratively". This audit row and `pos_shifts.notes` are the only
 * places that provenance survives. Whether a JET-visible marker is required is
 * owner ruling O-30(c), not a code decision.
 *
 * `releaseAuditEventId` is the `audit_events.id` of the `terminal.released`
 * row (with `forced=true` and `payload.open_shift_id` = this shift) that
 * AUTHORISED the close. The command refuses to run without one, so this field
 * is never empty and an auditor can always walk back from the close to the
 * release that caused it.
 *
 * The money fields carry the pair that was written, not a computation to be
 * re-run: `expectedCash` = opening float + the shift's cash movements,
 * `countedCash` = the same, `variance` = zero. No cash was counted, so no
 * shortage is asserted against a cashier who was never asked to count one.
 */
final class OrphanedShiftClosedByOperator extends DomainEvent
{
    /**
     * @param  numeric-string  $expectedCash
     * @param  numeric-string  $countedCash
     * @param  numeric-string  $variance
     */
    public function __construct(
        public readonly string $shiftId,
        public readonly string $terminalId,
        public readonly string $terminalCode,
        public readonly string $companyId,
        public readonly string $cashierId,
        public readonly string $reason,
        public readonly string $closedBy,
        public readonly string $releaseAuditEventId,
        public readonly string $expectedCash,
        public readonly string $countedCash,
        public readonly string $variance,
        public readonly string $closedAt,
    ) {
        parent::__construct($shiftId);
    }

    public function getEventName(): string
    {
        return 'shift.orphan_closed';
    }
}
