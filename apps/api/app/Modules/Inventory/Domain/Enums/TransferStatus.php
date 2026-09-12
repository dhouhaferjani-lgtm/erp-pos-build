<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Stock-transfer lifecycle states.
 *
 * draft                — created, no stock motion yet
 * in_transit           — source stock decremented, nothing received yet
 * partially_received   — at least one receipt posted, a positive remainder is still carrying
 * completed            — every unit accounted for by receipts; WAC capitalization applied
 * closed_with_writeoff — an open remainder was written off at destination (Shrinkage GL)
 * closed_returned      — an open remainder was returned to source (stock only, no GL)
 * cancelled            — voided; in_transit stock has been returned to source
 *
 * `closed_with_writeoff` is exactly 20 characters, which is the width of
 * `stock_transfers.status` (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:42`),
 * so this enum grows with NO column alteration.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Completed = 'completed';
    case ClosedWithWriteoff = 'closed_with_writeoff';
    case ClosedReturned = 'closed_returned';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Completed
            || $this === self::Cancelled
            || $this === self::ClosedWithWriteoff
            || $this === self::ClosedReturned;
    }

    public function canBeInitiated(): bool
    {
        return $this === self::Draft;
    }

    public function canBeCompleted(): bool
    {
        return $this === self::InTransit || $this === self::PartiallyReceived;
    }

    /** Unchanged from the pre-T-2 body: `partially_received` is deliberately NOT cancellable. */
    public function canBeCancelled(): bool
    {
        return $this === self::Draft || $this === self::InTransit;
    }

    public function canReceive(): bool
    {
        return $this === self::InTransit || $this === self::PartiallyReceived;
    }

    public function canBeClosed(): bool
    {
        return $this === self::InTransit || $this === self::PartiallyReceived;
    }

    /** The PHP twin of StockTransfer::CARRYING_STATUSES (§7.7). */
    public function isCarrying(): bool
    {
        return $this === self::InTransit || $this === self::PartiallyReceived;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InTransit => 'In Transit',
            self::PartiallyReceived => 'Partially Received',
            self::Completed => 'Completed',
            self::ClosedWithWriteoff => 'Closed (Written Off)',
            self::ClosedReturned => 'Closed (Returned)',
            self::Cancelled => 'Cancelled',
        };
    }
}
