<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Enums;

/**
 * Pricing strategy for a service bundle.
 *
 * - Standard: the bundle total equals the sum of its component line totals
 *   (each component carries its own price, override_unit_price, or the
 *   resolver-provided default).
 * - FixedBundle: the bundle has a flat authoritative `base_price`; component
 *   lines are informational (parts allocation + labor attribution) but do
 *   not contribute to the customer-facing total.
 */
enum BundlePricingMode: string
{
    case Standard = 'standard';
    case FixedBundle = 'fixed_bundle';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::FixedBundle => 'Fixed bundle',
        };
    }
}
