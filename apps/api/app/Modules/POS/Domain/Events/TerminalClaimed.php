<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a physical device binds itself to a terminal.
 *
 * Binding a device to a terminal binds it to that terminal's NF525 hash chain —
 * the same class of compliance-relevant act as activating or deactivating the
 * terminal, both of which have dispatched an audit event since the beginning
 * ({@see TerminalActivatedAudit}, {@see TerminalDeactivated}). Claiming
 * dispatched NOTHING: the 2026-08-23 state-machine sweep found that the only
 * trace of a device re-pointing was `pos_terminals.updated_at`. This event, and
 * its {@see TerminalReleased} counterpart, close the pair.
 *
 * AUDIT REGISTER ONLY — NOT the NF525 JET export. `Nf525DataProvider.php:292-297`
 * whitelists exactly three terminal event types (`terminal.activated`,
 * `terminal.deactivated`, `terminal.software_updated`) and `Nf525EventType` has
 * no case for a binding change, so this event lands in `audit_events` and is
 * absent from the JET — the same treatment `terminal.training_mode_changed`
 * already gets. Whether a device re-binding is a REPORTABLE NF525 terminal event
 * is an owner/certification decision, not a code change (the parent records the
 * owner row).
 */
final class TerminalClaimed extends DomainEvent
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $terminalCode,
        public readonly string $companyId,
        public readonly string $hardwareIdentifier,
        public readonly string $claimedBy,
    ) {
        parent::__construct($terminalId);
    }

    public function getEventName(): string
    {
        return 'terminal.claimed';
    }
}
