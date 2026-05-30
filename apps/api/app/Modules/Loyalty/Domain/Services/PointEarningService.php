<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Services;

use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
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
     * TND canonical floor — used when no currency can be resolved and no
     * CompanyContext is bound (e.g. queued listener, console command).
     */
    private const FALLBACK_SCALE = 3;

    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Resolve the currency scale without ever throwing.
     *
     * This service is driven by EarnPointsOnReceiptCompleted, which implements
     * ShouldQueue and therefore runs in a queue worker where no CompanyContext
     * is bound. Calling getScale() with no argument there throws
     * UnboundCompanyContextException, which the listener swallows — silently
     * dropping loyalty points. We resolve from the transaction currency when
     * available, and otherwise fall back via getScaleSafe() so the queued path
     * never throws.
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function resolveScale(array $transactionData = []): int
    {
        $currency = isset($transactionData['currency']) && is_string($transactionData['currency'])
            ? $transactionData['currency']
            : null;

        return $this->scaleResolver->getScaleSafe($currency, self::FALLBACK_SCALE);
    }

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

        $scale = $this->resolveScale($transactionData);

        // Calculate base points based on rule type
        $basePoints = $this->calculateBasePoints($rule, $transactionData);

        // Apply tier multiplier if enrollment has a tier
        $multipliedPoints = $this->applyTierMultiplier($basePoints, $enrollment, $scale);

        // Apply max earn per transaction cap if configured
        $cappedPoints = $this->applyTransactionCap($multipliedPoints, $rule, $scale);

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

        $scale = $this->resolveScale($transactionData);

        // Check minimum purchase amount
        if (isset($conditions['min_purchase_amount'])) {
            /** @var numeric-string $minAmount */
            $minAmount = (string) $conditions['min_purchase_amount'];
            // Use number_format to prevent scientific notation from float->string cast
            $transactionAmount = number_format(
                (float) ($transactionData['amount'] ?? 0),
                $scale + 4,
                '.',
                '',
            );

            if (bccomp($transactionAmount, $minAmount, $scale + 4) < 0) {
                return false;
            }
        }

        // Check maximum purchase amount
        if (isset($conditions['max_purchase_amount'])) {
            /** @var numeric-string $maxAmount */
            $maxAmount = (string) $conditions['max_purchase_amount'];
            // Use number_format to prevent scientific notation from float->string cast
            $transactionAmount = number_format(
                (float) ($transactionData['amount'] ?? 0),
                $scale + 4,
                '.',
                '',
            );

            if (bccomp($transactionAmount, $maxAmount, $scale + 4) > 0) {
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

        $scale = $this->resolveScale();
        /** @var numeric-string $dailyCap */
        $dailyCap = (string) $rule->max_earn_per_day;
        // Use number_format to prevent scientific notation from float->string cast
        $alreadyEarned = number_format($alreadyEarnedToday, $scale + 4, '.', '');
        $remaining = bcsub($dailyCap, $alreadyEarned, $scale + 4);

        if (bccomp($remaining, '0', $scale + 4) <= 0) {
            return PointsAmount::zero();
        }

        return $calculatedPoints->min(
            PointsAmount::fromNumeric((float) CurrencyScale::bcformat($remaining, $scale))
        );
    }

    /**
     * Calculate base points based on rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateBasePoints(EarningRule $rule, array $transactionData): PointsAmount
    {
        $rewardValue = (string) $rule->reward_value;
        $scale = $this->resolveScale($transactionData);

        return match ($rule->rule_type) {
            EarningRuleType::Spend => $this->calculateSpendPoints($rewardValue, $transactionData, $scale),
            EarningRuleType::Item => $this->calculateItemPoints($rewardValue, $rule, $transactionData, $scale),
            EarningRuleType::Category => $this->calculateCategoryPoints($rewardValue, $rule, $transactionData, $scale),
            EarningRuleType::Quantity => $this->calculateQuantityPoints($rewardValue, $transactionData, $scale),
            EarningRuleType::Visit => PointsAmount::fromNumeric(
                (float) CurrencyScale::bcformat($rewardValue, $scale)
            ),
            EarningRuleType::Threshold => $this->calculateThresholdPoints($rewardValue, $rule, $transactionData, $scale),
            EarningRuleType::Time => PointsAmount::fromNumeric(
                (float) CurrencyScale::bcformat($rewardValue, $scale)
            ),
        };
    }

    /**
     * Calculate points for SPEND rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateSpendPoints(string $rewardValue, array $transactionData, int $scale): PointsAmount
    {
        // Use number_format to prevent scientific notation from float->string cast
        $rawAmount = $transactionData['amount'] ?? '0';
        $amount = number_format((float) $rawAmount, $scale + 4, '.', '');
        /** @var numeric-string $rewardValue */
        $intermediate = bcmul($amount, $rewardValue, $scale + 4);

        return PointsAmount::fromNumeric((float) CurrencyScale::bcformat($intermediate, $scale));
    }

    /**
     * Calculate points for ITEM rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateItemPoints(string $rewardValue, EarningRule $rule, array $transactionData, int $scale): PointsAmount
    {
        $productIds = $rule->conditions['product_ids'] ?? [];
        $items = $transactionData['items'] ?? [];

        $matchingQuantity = 0;

        foreach ($items as $item) {
            if (in_array($item['product_id'], $productIds, true)) {
                $matchingQuantity += (int) ($item['quantity'] ?? 1);
            }
        }

        /** @var numeric-string $rewardValue */
        $intermediate = bcmul((string) $matchingQuantity, $rewardValue, $scale + 4);

        return PointsAmount::fromNumeric((float) CurrencyScale::bcformat($intermediate, $scale));
    }

    /**
     * Calculate points for CATEGORY rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateCategoryPoints(string $rewardValue, EarningRule $rule, array $transactionData, int $scale): PointsAmount
    {
        $categoryIds = $rule->conditions['category_ids'] ?? [];
        $items = $transactionData['items'] ?? [];

        $matchingQuantity = 0;

        foreach ($items as $item) {
            if (isset($item['category_id']) && in_array($item['category_id'], $categoryIds, true)) {
                $matchingQuantity += (int) ($item['quantity'] ?? 1);
            }
        }

        /** @var numeric-string $rewardValue */
        $intermediate = bcmul((string) $matchingQuantity, $rewardValue, $scale + 4);

        return PointsAmount::fromNumeric((float) CurrencyScale::bcformat($intermediate, $scale));
    }

    /**
     * Calculate points for QUANTITY rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateQuantityPoints(string $rewardValue, array $transactionData, int $scale): PointsAmount
    {
        $totalQuantity = $this->getTotalQuantity($transactionData);
        /** @var numeric-string $rewardValue */
        $intermediate = bcmul((string) $totalQuantity, $rewardValue, $scale + 4);

        return PointsAmount::fromNumeric((float) CurrencyScale::bcformat($intermediate, $scale));
    }

    /**
     * Calculate points for THRESHOLD rule type
     *
     * @param  array<string, mixed>  $transactionData
     */
    private function calculateThresholdPoints(string $rewardValue, EarningRule $rule, array $transactionData, int $scale): PointsAmount
    {
        /** @var numeric-string $minAmount */
        $minAmount = (string) ($rule->conditions['min_purchase_amount'] ?? '0');
        // Use number_format to prevent scientific notation from float->string cast
        $transactionAmount = number_format(
            (float) ($transactionData['amount'] ?? 0),
            $scale + 4,
            '.',
            '',
        );

        // Use bccomp for decimal comparison (no float cast)
        if (bccomp($transactionAmount, $minAmount, $scale + 4) >= 0) {
            return PointsAmount::fromNumeric(
                (float) CurrencyScale::bcformat($rewardValue, $scale)
            );
        }

        return PointsAmount::zero();
    }

    /**
     * Apply tier multiplier to points
     */
    private function applyTierMultiplier(PointsAmount $points, Enrollment $enrollment, int $scale): PointsAmount
    {
        $tier = $enrollment->currentTier;

        if ($tier === null) {
            return $points;
        }

        /** @var numeric-string $multiplier */
        $multiplier = (string) $tier->earning_multiplier;
        // Use number_format to prevent scientific notation from float->string cast
        $pointsStr = number_format($points->value, $scale + 4, '.', '');
        $intermediate = bcmul($pointsStr, $multiplier, $scale + 4);

        return PointsAmount::fromNumeric((float) CurrencyScale::bcformat($intermediate, $scale));
    }

    /**
     * Apply transaction cap to points
     */
    private function applyTransactionCap(PointsAmount $points, EarningRule $rule, int $scale): PointsAmount
    {
        if ($rule->max_earn_per_transaction === null) {
            return $points;
        }

        $cap = PointsAmount::fromNumeric(
            (float) CurrencyScale::bcformat((string) $rule->max_earn_per_transaction, $scale)
        );

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
