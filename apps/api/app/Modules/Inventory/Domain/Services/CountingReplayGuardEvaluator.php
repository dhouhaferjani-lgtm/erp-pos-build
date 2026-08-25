<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\InventoryScale;

/** Single source of truth for replay guards shared by preview and apply. */
final class CountingReplayGuardEvaluator
{
    /**
     * Every pre-apply reason the line earns, in the order it is recorded.
     *
     * Returning a LIST (campaign W4-6) rather than the first hit is the whole
     * point: `basket_window` and `pending_opening_cost` answer different
     * questions and only the latter withholds the stock write. Ask
     * {@see CountingItemFlagReason::blocksStockApplication()} of the result —
     * never "is the list non-empty".
     *
     * @return list<CountingItemFlagReason>
     */
    public function preApply(bool $hasMovementNear, bool $openingCostMissing): array
    {
        $reasons = [];

        if ($hasMovementNear) {
            $reasons[] = CountingItemFlagReason::BasketWindow;
        }

        if ($openingCostMissing) {
            $reasons[] = CountingItemFlagReason::PendingOpeningCost;
        }

        return $reasons;
    }

    /**
     * The first pre-apply reason that actually withholds the stock write, or
     * null when the line posts.
     */
    public function preApplyBlocker(bool $hasMovementNear, bool $openingCostMissing): ?CountingItemFlagReason
    {
        foreach ($this->preApply($hasMovementNear, $openingCostMissing) as $reason) {
            if ($reason->blocksStockApplication()) {
                return $reason;
            }
        }

        return null;
    }

    /** @param numeric-string $expectedNow */
    public function atApply(bool $onboarding, string $expectedNow): ?CountingItemFlagReason
    {
        return ! $onboarding && bccomp($expectedNow, '0', InventoryScale::QUANTITY_SCALE) < 0
            ? CountingItemFlagReason::NegativeAtApply
            : null;
    }
}
