<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS terminal is deactivated.
 *
 * NF525 DESACTIVATION_TERMINAL event - every terminal deactivation must be logged
 * in the JET (Journal des Evenements Techniques) for fiscal compliance.
 */
final class TerminalDeactivated extends DomainEvent
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $terminalCode,
        public readonly string $companyId,
        public readonly string $reason,
        public readonly string $deactivatedBy,
    ) {
        parent::__construct($terminalId);
    }

    public function getEventName(): string
    {
        return 'terminal.deactivated';
    }
}
