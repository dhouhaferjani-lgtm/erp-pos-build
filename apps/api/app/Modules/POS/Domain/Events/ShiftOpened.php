<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS shift is opened.
 *
 * NF525 OUVERTURE_CAISSE event - shift openings must be tracked
 * for cash drawer audit trail and fraud detection.
 */
final class ShiftOpened extends DomainEvent
{
    public function __construct(
        public readonly string $shiftId,
        public readonly string $companyId,
        public readonly string $terminalId,
        public readonly string $cashierId,
        public readonly string $openingBalance,
        public readonly string $openedAt,
    ) {
        parent::__construct($shiftId);
    }

    public function getEventName(): string
    {
        return 'shift.opened';
    }
}
