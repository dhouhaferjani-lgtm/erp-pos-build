<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Services;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use Illuminate\Support\Carbon;

/**
 * Service for calculating loyalty points based on earning rules
 *
 * This service evaluates earning rules against transactions and calculates
 * the points earned, applying tier multipliers and caps as configured.
 */
final readonly class PointEarningService
{
    /**
     * Calculate points earned for a transaction
     *
     * @param  Enrollment  $enrollment  The member's enrollment with tier info
     * @param  array<string, mixed>  $transactionData  Transaction details (amount, items, etc.)
     * @param  EarningRule  $rule  The earning rule to apply
     * @return PointsAmount The calculated points amount
     */
    public function calculatePoints(
        Enrollment $enrollment,
        array $transactionData,
        EarningRule $rule
    ): PointsAmount {
        // Check if rule is valid (active and within date range)
        if (! $rule->isValid()) {
            return PointsAmount::zero();
        }

        // Check if rule applies to this transaction
        if (! $this->ruleApplies($rule, $transactionData)) {
            return PointsAmount::zero();
        }

        // Calculate base points based on rule type
        $basePoints = $this->calculateBasePoints($rule, $transactionData);

        // Apply tier multiplier if enrollment has a tier
        $multipliedPoints = $this->applyTierMultiplier($basePoints, $enrollment);

        // Apply max earn per transaction cap if configured
        $cappedPoints = $this->applyTransactionCap($multipliedPoints, $rule);

        return $cappedPoints;
    }

    /**
     * Evaluate if a rule applies to a transaction
     *
     * @param  array<string, mixed>  $transactionData
     */
    public function ruleApplies(EarningRule $rule, array $transactionData): bool
    {
        $conditions = $rule->conditions;

        // If no conditions, rule applies to all transactions
        if (empty($conditions)) {
            return true;
        }

        // Check minimum purchase amount
        if (isset($conditions['min_purchase_amount'])) {
            $minAmount = (float) $conditions['min_purchase_amount'];
            $transactionAmount = (float) ($transactionData['amount'] ?? 0);

            if ($transactionAmount < $minAmount) {
                return false;
            }
        }

        // Check maximum purchase amount
        if (isset($conditions['max_purchase_amount'])) {
            $maxAmount = (float) $conditions['max_purchase_amount'];
            $transactionAmount = (float) ($transactionData['amount'] ?? 0);

            if ($transactionAmount > $maxAmount) {
                return false;
            }
        }

        // Check product IDs - at least one product must match
        if (isset($conditions['product_ids']) && ! empty($conditions['product_ids'])) {
            $requiredProducts = $conditions['product_ids'];
            $transactionProducts = array_column($transactionData['items'] ?? [], 'product_id');

            if (empty(array_intersect($requiredProducts, $transactionProducts))) {
                return false;
            }
        }

        // Check category IDs - at least one category must match
        if (isset($conditions['category_ids']) && ! empty($conditions['category_ids'])) {
            $requiredCategories = $conditions['category_ids'];
            $transactionCategories = array_column($transactionData['items'] ?? [], 'category_id');

            if (empty(array_intersect($requiredCategories, $transactionCategories))) {
                return false;
            }
        }

        // Check time-based conditions
        if (isset($conditions['time_start']) || isset($conditions['time_end'])) {
            $timestamp = $transactionData['timestamp'] ?? now();
            if (! ($timestamp instanceof Carbon)) {
                $timestamp = Carbon::parse($timestamp);
            }

            $time = $timestamp->format('H:i');

            if (isset($conditions['time_start']) && $time < $conditions['time_start']) {
                return false;
            }

            if (isset($conditions['time_end']) && $time > $conditions['time_end']) {
                return false;
            }
        }

        // Check day of week
        if (isset($conditions['day_of_week']) && ! empty($conditions['day_of_week'])) {
            $timestamp = $transactionData['timestamp'] ?? now();
            if (! ($timestamp instanceof Carbon)) {
                $timestamp = Carbon::parse($timestamp);
            }

            $dayOfWeek = (int) $timestamp->dayOfWeekIso; // 1 = Monday, 7 = Sunday

            if (! in_array($dayOfWeek, $conditions['day_of_week'], true)) {
                return false;
            }
        }

        // Check minimum quantity
        if (isset($conditions['min_quantity'])) {
            $minQty = (int) $conditions['min_quantity'];
            $totalQty = $this->getTotalQuantity($transactionData);

            if ($totalQty < $minQty) {
                return false;
            }
        }

        // Check maximum quantity
        if (isset($conditions['max_quantity'])) {
            $maxQty = (int) $conditions['max_quantity'];
            $totalQty = $this->getTotalQuantity($transactionData);

            if ($totalQty > $maxQty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply daily cap to calculated points
     *
     * @param  float  $alreadyEarnedToday  Points already earned today
     */
    public function applyDailyCap(
        PointsAmount $calculatedPoints,
        EarningRule $rule,
        float $alreadyEarnedToday
    ): PointsAmount {
        if ($rule->max_earn_per_day === null) {
            return $calculatedPoints;
        }

        $dailyCap = (float) $rule->max_earn_per_day;
        $remaining = max(0, $dailyCap - $alreadyEarnedToday);

        if ($remaining <= 0) {
            return PointsAmount::zero();
        }

        return $calculatedPoints->min(PointsAmount::fromNumeric($remaining));
    }

    /**
     * Calculate base points based on rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateBasePoints(EarningRule $rule, array $transactionData): PointsAmount
    {
        $rewardValue = (float) $rule->reward_value;

        return match ($rule->rule_type) {
            EarningRuleType::Spend => $this->calculateSpendPoints($rewardValue, $transactionData),
            EarningRuleType::Item => $this->calculateItemPoints($rewardValue, $rule, $transactionData),
            EarningRuleType::Category => $this->calculateCategoryPoints($rewardValue, $rule, $transactionData),
            EarningRuleType::Quantity => $this->calculateQuantityPoints($rewardValue, $transactionData),
            EarningRuleType::Visit => PointsAmount::fromNumeric($rewardValue),
            EarningRuleType::Threshold => $this->calculateThresholdPoints($rewardValue, $rule, $transactionData),
            EarningRuleType::Time => PointsAmount::fromNumeric($rewardValue),
        };
    }

    /**
     * Calculate points for SPEND rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateSpendPoints(float $rewardValue, array $transactionData): PointsAmount
    {
        $amount = (float) ($transactionData['amount'] ?? 0);

        return PointsAmount::fromNumeric($amount * $rewardValue);
    }

    /**
     * Calculate points for ITEM rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateItemPoints(float $rewardValue, EarningRule $rule, array $transactionData): PointsAmount
    {
        $productIds = $rule->conditions['product_ids'] ?? [];
        $items = $transactionData['items'] ?? [];

        $matchingQuantity = 0;

        foreach ($items as $item) {
            if (in_array($item['product_id'], $productIds, true)) {
                $matchingQuantity += (int) ($item['quantity'] ?? 1);
            }
        }

        return PointsAmount::fromNumeric($matchingQuantity * $rewardValue);
    }

    /**
     * Calculate points for CATEGORY rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateCategoryPoints(float $rewardValue, EarningRule $rule, array $transactionData): PointsAmount
    {
        $categoryIds = $rule->conditions['category_ids'] ?? [];
        $items = $transactionData['items'] ?? [];

        $matchingQuantity = 0;

        foreach ($items as $item) {
            if (isset($item['category_id']) && in_array($item['category_id'], $categoryIds, true)) {
                $matchingQuantity += (int) ($item['quantity'] ?? 1);
            }
        }

        return PointsAmount::fromNumeric($matchingQuantity * $rewardValue);
    }

    /**
     * Calculate points for QUANTITY rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateQuantityPoints(float $rewardValue, array $transactionData): PointsAmount
    {
        $totalQuantity = $this->getTotalQuantity($transactionData);

        return PointsAmount::fromNumeric($totalQuantity * $rewardValue);
    }

    /**
     * Calculate points for THRESHOLD rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateThresholdPoints(float $rewardValue, EarningRule $rule, array $transactionData): PointsAmount
    {
        $minAmount = (float) ($rule->conditions['min_purchase_amount'] ?? 0);
        $transactionAmount = (float) ($transactionData['amount'] ?? 0);

        if ($transactionAmount >= $minAmount) {
            return PointsAmount::fromNumeric($rewardValue);
        }

        return PointsAmount::zero();
    }

    /**
     * Apply tier multiplier to points
     */
    private function applyTierMultiplier(PointsAmount $points, Enrollment $enrollment): PointsAmount
    {
        $tier = $enrollment->currentTier;

        if ($tier === null) {
            return $points;
        }

        $multiplier = (float) $tier->earning_multiplier;

        return $points->multiply($multiplier);
    }

    /**
     * Apply transaction cap to points
     */
    private function applyTransactionCap(PointsAmount $points, EarningRule $rule): PointsAmount
    {
        if ($rule->max_earn_per_transaction === null) {
            return $points;
        }

        $cap = PointsAmount::fromNumeric((float) $rule->max_earn_per_transaction);

        return $points->min($cap);
    }

    /**
     * Get total quantity from transaction items
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function getTotalQuantity(array $transactionData): int
    {
        $items = $transactionData['items'] ?? [];
        $total = 0;

        foreach ($items as $item) {
            $total += (int) ($item['quantity'] ?? 1);
        }

        return $total;
    }
}
