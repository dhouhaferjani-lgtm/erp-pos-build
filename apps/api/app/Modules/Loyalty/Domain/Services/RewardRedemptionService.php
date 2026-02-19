<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Services;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Enums\RewardType;
use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use Carbon\Carbon;

/**
 * Domain service for reward redemption logic
 *
 * Handles eligibility checks, value calculations, and validation
 * for loyalty reward redemptions.
 */
final readonly class RewardRedemptionService
{
    /**
     * Check if member is eligible to redeem reward
     *
     * Validates:
     * - Reward is active
     * - Member has sufficient points
     * - Member meets tier requirements
     * - Reward is within date range
     * - Quantity limits not exceeded
     *
     * @param  int  $memberRedemptionCount  Number of times member has redeemed this reward
     */
    public function isEligible(Enrollment $enrollment, Reward $reward, int $memberRedemptionCount = 0): bool
    {
        // Check if reward is active
        if (! $reward->is_active) {
            return false;
        }

        // Check if member has sufficient points
        if ($enrollment->current_balance < $reward->points_cost) {
            return false;
        }

        // Check tier requirements
        // Reward may restrict redemption to specific tiers
        $tierIds = $reward->tier_ids;
        if ($tierIds !== null && count($tierIds) > 0) {
            $currentTierId = $enrollment->current_tier_id;

            // If reward requires specific tiers but member has no tier, they're not eligible
            if ($currentTierId === null) {
                return false;
            }

            // Check if member's tier is in the allowed tiers
            if (! in_array($currentTierId, $tierIds, true)) {
                return false;
            }
        }

        // Check if reward has started
        if ($reward->start_date !== null && Carbon::now()->isBefore($reward->start_date)) {
            return false;
        }

        // Check if reward has expired
        if ($reward->end_date !== null && Carbon::now()->isAfter($reward->end_date)) {
            return false;
        }

        // Check quantity available
        if ($reward->quantity_available !== null && $reward->quantity_available <= 0) {
            return false;
        }

        // Check per-member quantity limit
        if ($reward->quantity_per_member !== null && $memberRedemptionCount >= $reward->quantity_per_member) {
            return false;
        }

        return true;
    }

    /**
     * Calculate points cost for redemption
     */
    public function calculateCost(Reward $reward): PointsAmount
    {
        return new PointsAmount((float) $reward->points_cost);
    }

    /**
     * Calculate monetary value of reward
     *
     * Handles different reward types:
     * - FreeItem: Returns reward value directly
     * - DiscountAmount: Returns fixed discount value
     * - DiscountPercent: Calculates percentage of subtotal with max cap
     * - Credit: Returns credit amount
     * - Choice/External: Returns reward value
     *
     * @param  array<string, mixed>  $transactionContext
     */
    public function calculateValue(Reward $reward, array $transactionContext = []): float
    {
        return match ($reward->reward_type) {
            RewardType::FreeItem => (float) $reward->reward_value,
            RewardType::DiscountAmount => (float) $reward->reward_value,
            RewardType::DiscountPercent => $this->calculatePercentageDiscount($reward, $transactionContext),
            RewardType::Credit => (float) $reward->reward_value,
            RewardType::Choice, RewardType::External => (float) $reward->reward_value,
        };
    }

    /**
     * Validate qualifying items if configured
     *
     * Checks if cart contains at least one item from the
     * qualifying items list. Returns true if no qualifying
     * items are configured (applies to all items).
     *
     * @param  array<int, array<string, mixed>>  $cartItems
     */
    public function validateQualifyingItems(Reward $reward, array $cartItems): bool
    {
        $qualifyingItems = $reward->qualifying_items;

        // If no qualifying items configured, all items are valid
        if ($qualifyingItems === null || ! isset($qualifyingItems['product_ids'])) {
            return true;
        }

        $qualifyingProductIds = $qualifyingItems['product_ids'];

        // Check if any cart item matches qualifying items
        foreach ($cartItems as $item) {
            if (in_array($item['product_id'], $qualifyingProductIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate percentage discount with max cap
     *
     * @param  array<string, mixed>  $transactionContext
     */
    private function calculatePercentageDiscount(Reward $reward, array $transactionContext): float
    {
        $subtotal = $transactionContext['subtotal'] ?? 0.0;
        $percentage = (float) $reward->reward_value;

        $calculatedDiscount = ($subtotal * $percentage) / 100;

        // Apply max discount cap if configured
        if ($reward->max_discount !== null && $calculatedDiscount > (float) $reward->max_discount) {
            return (float) $reward->max_discount;
        }

        return $calculatedDiscount;
    }
}
