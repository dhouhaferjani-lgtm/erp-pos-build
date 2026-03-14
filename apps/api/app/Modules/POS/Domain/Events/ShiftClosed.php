<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS shift is closed.
 *
 * NF525 FERMETURE_CAISSE event - shift closings with cash counts
 * and variance are critical for fraud detection.
 */
final class ShiftClosed extends DomainEvent
{
    public function __construct(
        public readonly string $shiftId,
        public readonly string $companyId,
        public readonly string $terminalId,
        public readonly string $cashierId,
        public readonly string $expectedCash,
        public readonly string $actualCash,
        public readonly string $variance,
        public readonly string $closedAt,
    ) {
        parent::__construct($shiftId);
    }

    public function getEventName(): string
    {
        return 'shift.closed';
    }
}
