<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when an administrator unbinds a device from a terminal.
 *
 * Shaped to mirror {@see TerminalDeactivated} — the sibling remedial act on the
 * same row — so the AUDIT REGISTER reads consistently: what, which terminal,
 * whose company, why, and by whom. `hardwareIdentifier` records the binding that
 * was BROKEN (the column is null afterwards), which is the only thing an auditor
 * asking "which device used to author this chain?" can work from.
 *
 * AUDIT REGISTER ONLY — NOT the NF525 JET export. `Nf525DataProvider.php:292-297`
 * whitelists exactly three terminal event types (`terminal.activated`,
 * `terminal.deactivated`, `terminal.software_updated`) and `Nf525EventType` has
 * no case for a binding change, so this event lands in `audit_events` and is
 * absent from the JET. That matches the existing treatment of
 * `terminal.training_mode_changed`. Whether a device re-binding is a REPORTABLE
 * NF525 terminal event is an owner/certification decision, not a code change
 * (the parent records the owner row); adding a JET event type would alter a
 * certified export's contents.
 *
 * `forced` / `openShiftId` (Q-7 fix round, F-1): a release refused by default
 * because the terminal still had an OPEN shift, then overridden. The shift is
 * ORPHANED by that override — no server surface can close a v3 terminal's shift
 * without the device — so the audit row must name the shift that was abandoned.
 */
final class TerminalReleased extends DomainEvent
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $terminalCode,
        public readonly string $companyId,
        public readonly string $hardwareIdentifier,
        public readonly string $reason,
        public readonly string $releasedBy,
        public readonly bool $forced = false,
        public readonly ?string $openShiftId = null,
    ) {
        parent::__construct($terminalId);
    }

    public function getEventName(): string
    {
        return 'terminal.released';
    }
}
