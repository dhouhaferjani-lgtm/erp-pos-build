<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum MovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Adjustment = 'adjustment';
    case Opening = 'opening';

    public function isInbound(): bool
    {
        return in_array($this, [self::Receipt, self::TransferIn, self::Adjustment, self::Opening], true);
    }

    public function isOutbound(): bool
    {
        return in_array($this, [self::Issue, self::TransferOut], true);
    }

    /**
     * Check if this movement type is for opening balance
     */
    public function isOpening(): bool
    {
        return $this === self::Opening;
    }
}
