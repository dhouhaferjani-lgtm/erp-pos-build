<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum TaxApplicationLevel: string
{
    case LineItems = 'LINE_ITEMS';
    case DocumentTotal = 'DOCUMENT_TOTAL';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::LineItems => 'Line Items',
            self::DocumentTotal => 'Document Total',
        };
    }
}
