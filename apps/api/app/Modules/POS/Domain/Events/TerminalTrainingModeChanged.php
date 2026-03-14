<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a terminal's training mode is toggled on or off.
 *
 * NF525 compliance event - training mode changes must be audit-logged
 * to track when terminals switch between production and training modes.
 */
final class TerminalTrainingModeChanged extends DomainEvent
{
    public function __construct(
        public readonly string $terminalId,
        public readonly string $terminalCode,
        public readonly string $companyId,
        public readonly bool $enabled,
        public readonly string $changedBy,
    ) {
        parent::__construct($terminalId);
    }

    public function getEventName(): string
    {
        return 'terminal.training_mode_changed';
    }
}
