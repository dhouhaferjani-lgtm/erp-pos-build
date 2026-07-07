<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Reasons an InventoryCountingItem's `flag_reasons` jsonb array may contain.
 *
 * Flag semantics (normative for B2/B3/B4/D3): `is_flagged=true` iff
 * `flag_reasons` contains a BLOCKING reason (basket_window,
 * negative_at_apply, clock_skew, pending_opening_cost). `normalized_agreement`
 * is informational only — it is appended to `flag_reasons` but NEVER sets
 * `is_flagged`.
 *
 * `pending_opening_cost` (D3) is raised when an onboarding first-count line has
 * no resolvable positive opening cost (item override unset AND the product's
 * `cost_price` is ≤ 0). Posting it would silently establish a zero-cost opening
 * balance, so the line is BLOCKED and left for review until the cost is
 * backfilled via the opening-cost endpoint (an explicit endpoint-set cost of 0
 * is a deliberate zero-cost opening and is NOT blocked).
 */
enum CountingItemFlagReason: string
{
    case BasketWindow = 'basket_window';
    case NegativeAtApply = 'negative_at_apply';
    case ClockSkew = 'clock_skew';
    case PendingOpeningCost = 'pending_opening_cost';
    case NormalizedAgreement = 'normalized_agreement';

    /**
     * Whether this reason alone forces `is_flagged=true` on the item.
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::BasketWindow, self::NegativeAtApply, self::ClockSkew, self::PendingOpeningCost => true,
            self::NormalizedAgreement => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BasketWindow => 'Sale in Basket Window',
            self::NegativeAtApply => 'Negative Stock at Apply',
            self::ClockSkew => 'Clock Skew Detected',
            self::PendingOpeningCost => 'Opening Cost Required',
            self::NormalizedAgreement => 'Normalized Agreement',
        };
    }
}
