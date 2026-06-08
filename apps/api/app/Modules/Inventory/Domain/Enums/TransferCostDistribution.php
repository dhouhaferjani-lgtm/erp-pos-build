<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * How additional transfer costs (freight, handling) are allocated across
 * the transfer's lines before being capitalized into each product's
 * company-wide WAC.
 *
 * ProRataValue    — allocate proportional to (quantity × unit_cost_snapshot)
 * ProRataQuantity — allocate proportional to quantity
 * EqualPerLine    — split evenly across lines
 */
enum TransferCostDistribution: string
{
    case ProRataValue = 'pro_rata_value';
    case ProRataQuantity = 'pro_rata_quantity';
    case EqualPerLine = 'equal_per_line';

    public function label(): string
    {
        return match ($this) {
            self::ProRataValue => 'Pro-rata by Value',
            self::ProRataQuantity => 'Pro-rata by Quantity',
            self::EqualPerLine => 'Equal per Line',
        };
    }
}
