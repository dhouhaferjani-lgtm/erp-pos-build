<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

/**
 * Withholding direction from company perspective.
 *
 * - PURCHASE: We withhold tax from supplier payments (we owe tax authority)
 * - SALES: Customer withholds tax from our invoice payments (tax authority owes us)
 */
enum WithholdingDirection: string
{
    case PURCHASE = 'purchase';
    case SALES = 'sales';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Purchase (We Withhold)',
            self::SALES => 'Sales (They Withhold)',
        };
    }

    public function glAccountBase(): string
    {
        return match ($this) {
            self::PURCHASE => '42236', // Liability: we owe tax authority
            self::SALES => '42237',    // Asset: tax authority owes us
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PURCHASE => 'Tax withheld from payments we make to suppliers',
            self::SALES => 'Tax withheld by customers from payments they make to us',
        };
    }
}
