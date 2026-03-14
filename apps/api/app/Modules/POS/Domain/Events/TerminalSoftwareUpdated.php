<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS terminal's software version changes.
 *
 * NF525 MAJ_LOGICIEL event - every software update on a terminal must be logged
 * in the JET (Journal des Evenements Techniques) for fiscal compliance.
 */
final class TerminalSoftwareUpdated extends DomainEvent
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $terminalCode,
        public readonly string $companyId,
        public readonly string $previousVersion,
        public readonly string $newVersion,
    ) {
        parent::__construct($terminalId);
    }

    public function getEventName(): string
    {
        return 'terminal.software_updated';
    }
}
