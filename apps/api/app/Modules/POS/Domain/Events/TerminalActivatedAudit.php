<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS terminal is activated, for audit trail purposes.
 *
 * NF525 ACTIVATION_TERMINAL event - every terminal activation must be logged
 * in the JET (Journal des Evenements Techniques) for fiscal compliance.
 *
 * This is separate from the TerminalActivated Laravel event which uses
 * Dispatchable+SerializesModels for WebSocket broadcasting.
 */
final class TerminalActivatedAudit extends DomainEvent
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $terminalCode,
        public readonly string $companyId,
        public readonly string $activatedBy,
    ) {
        parent::__construct($terminalId);
    }

    public function getEventName(): string
    {
        return 'terminal.activated';
    }
}
