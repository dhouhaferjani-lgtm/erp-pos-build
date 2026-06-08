<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Stock-transfer lifecycle states.
 *
 * draft       — created, no stock motion yet
 * in_transit  — source stock decremented, awaiting completion at destination
 * completed   — destination stock incremented, WAC capitalization applied
 * cancelled   — voided; in_transit stock has been returned to source
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    public function canBeInitiated(): bool
    {
        return $this === self::Draft;
    }

    public function canBeCompleted(): bool
    {
        return $this === self::InTransit;
    }

    public function canBeCancelled(): bool
    {
        return $this === self::Draft || $this === self::InTransit;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InTransit => 'In Transit',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
