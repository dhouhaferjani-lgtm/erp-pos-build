<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Reasons an InventoryCountingItem's `flag_reasons` jsonb array may contain.
 *
 * Flag semantics (normative for B2/B3/B4/D3): `is_flagged=true` iff
 * `flag_reasons` contains a BLOCKING reason (basket_window,
 * negative_at_apply, clock_skew). `normalized_agreement` is informational
 * only — it is appended to `flag_reasons` but NEVER sets `is_flagged`.
 */
enum CountingItemFlagReason: string
{
    case BasketWindow = 'basket_window';
    case NegativeAtApply = 'negative_at_apply';
    case ClockSkew = 'clock_skew';
    case NormalizedAgreement = 'normalized_agreement';

    /**
     * Whether this reason alone forces `is_flagged=true` on the item.
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::BasketWindow, self::NegativeAtApply, self::ClockSkew => true,
            self::NormalizedAgreement => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BasketWindow => 'Sale in Basket Window',
            self::NegativeAtApply => 'Negative Stock at Apply',
            self::ClockSkew => 'Clock Skew Detected',
            self::NormalizedAgreement => 'Normalized Agreement',
        };
    }
}
