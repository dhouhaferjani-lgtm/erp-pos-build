<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Posted = 'posted';
    case Paid = 'paid';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /**
     * Check if document can be edited in this status
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Confirmed => true,
            self::Posted, self::Paid, self::Received, self::Cancelled => false,
        };
    }

    /**
     * Check if document can be deleted in this status
     */
    public function isDeletable(): bool
    {
        return match ($this) {
            self::Draft => true,
            self::Confirmed, self::Posted, self::Paid, self::Received, self::Cancelled => false,
        };
    }

    /**
     * A TERMINAL status: the document has been withdrawn and no later event may
     * move it out again.
     *
     * W-7 F-6: nothing on the payment-allocation path ever asked this. The five
     * status writes that flip a document to `Paid` are each gated only on
     * `DocumentType::canTransitionToPaid()`, which is a pure TYPE match and says
     * nothing about status, so a cancelled invoice was rewritten to `paid` with
     * `cancelled_at` still populated.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled => true,
            self::Draft, self::Confirmed, self::Posted, self::Paid, self::Received => false,
        };
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Posted => 'Posted',
            self::Paid => 'Paid',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }
}
