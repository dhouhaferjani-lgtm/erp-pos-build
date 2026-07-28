<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\InventoryScale;

/** Single source of truth for replay guards shared by preview and apply. */
final class CountingReplayGuardEvaluator
{
    public function preApply(bool $hasMovementNear, bool $openingCostMissing): ?CountingItemFlagReason
    {
        if ($hasMovementNear) {
            return CountingItemFlagReason::BasketWindow;
        }

        if ($openingCostMissing) {
            return CountingItemFlagReason::PendingOpeningCost;
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
