<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferDiscrepancyReason: string
{
    case ShortShipped = 'short_shipped';
    case LostInTransit = 'lost_in_transit';
    case DamagedInTransit = 'damaged_in_transit';
    case Other = 'other';

    /**
     * A receiver declares physical damage, never shortness (owner ruling D2);
     * a closer may use any of the four.
     */
    public function allowedFor(TransferReceiptKind $kind): bool
    {
        return match ($kind) {
            TransferReceiptKind::Receipt => $this === self::DamagedInTransit || $this === self::Other,
            TransferReceiptKind::Close => true,
            TransferReceiptKind::LegacyCompletion => false,
        };
    }
}
