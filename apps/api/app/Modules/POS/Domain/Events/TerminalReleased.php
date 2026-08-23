<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when an administrator unbinds a device from a terminal.
 *
 * Shaped to mirror {@see TerminalDeactivated} — the sibling remedial act on the
 * same row — so the JET/audit register reads consistently: what, which terminal,
 * whose company, why, and by whom. `hardwareIdentifier` records the binding that
 * was BROKEN (the column is null afterwards), which is the only thing an auditor
 * asking "which device used to author this chain?" can work from.
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
    ) {
        parent::__construct($terminalId);
    }

    public function getEventName(): string
    {
        return 'terminal.released';
    }
}
