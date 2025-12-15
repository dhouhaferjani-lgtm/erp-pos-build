<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum OpeningBatchType: string
{
    case Accounting = 'ACCOUNTING';
    case Inventory = 'INVENTORY';
    case ArOpenItems = 'AR_OPEN_ITEMS';
    case ApOpenItems = 'AP_OPEN_ITEMS';

    public function label(): string
    {
        return match ($this) {
            self::Accounting => 'GL Opening Balances',
            self::Inventory => 'Inventory Opening',
            self::ArOpenItems => 'Customer Open Items',
            self::ApOpenItems => 'Supplier Open Items',
        };
    }

    public function rowType(): string
    {
        return match ($this) {
            self::Accounting => 'GL',
            self::Inventory => 'INVENTORY',
            self::ArOpenItems => 'AR',
            self::ApOpenItems => 'AP',
        };
    }

    /**
     * Check if this batch type affects the general ledger directly
     */
    public function affectsGL(): bool
    {
        return match ($this) {
            self::Accounting, self::Inventory => true,
            self::ArOpenItems, self::ApOpenItems => false, // AR/AP items don't create GL (assumes GL already done)
        };
    }
}
