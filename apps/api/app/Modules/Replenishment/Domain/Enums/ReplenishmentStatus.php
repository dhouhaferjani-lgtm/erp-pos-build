<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Enums;

enum ReplenishmentStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Fulfilled = 'fulfilled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}
