<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\Services;

use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\CartItemContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;
use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * Pure domain logic for evaluating individual promotions against a cart.
 */
final class PromotionEvaluationService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Evaluate a single promotion against the cart.
     *
     * @return array<int, PromotionDiscount>
     */
    public function evaluate(Promotion $promotion, CartContext $cart): array
    {
        return match ($promotion->type) {
            PromotionType::HappyHour => $this->evaluateHappyHour($promotion, $cart),
            PromotionType::BuyXGetY => $this->evaluateBuyXGetY($promotion, $cart),
            PromotionType::VolumeDiscount => $this->evaluateVolumeDiscount($promotion, $cart),
            PromotionType::CategoryDiscount => $this->evaluateCategoryDiscount($promotion, $cart),
            PromotionType::ComboDiscount => $this->evaluateComboDiscount($promotion, $cart),
        };
    }

    /**
     * Happy Hour: flat % or fixed off entire transaction during time window.
     * Time-window check is handled by Promotion::isCurrentlyActive().
     *
     * @return array<int, PromotionDiscount>
     */
    private function evaluateHappyHour(Promotion $promotion, CartContext $cart): array
    {
        [$discountValue, $maxDiscount] = $this->getPromotionAmounts($promotion);

        $discountAmount = $this->calculateDiscount(
            $promotion->discount_type,
            $discountValue,
            $cart->subtotal,
            $maxDiscount,
        );

        if (bccomp($discountAmount, '0', $this->scale()) <= 0) {
            return [];
        }

        return [
            new PromotionDiscount(
                promotionId: $promotion->id,
                promotionName: $promotion->name,
                discountAmount: $discountAmount,
                appliesTo: DiscountAppliesTo::Transaction,
                targetProductId: null,
                isExclusive: $promotion->is_exclusive,
                stackingGroup: $promotion->stacking_group,
                priority: $promotion->priority,
            ),
        ];
    }

    /**
     * Buy X Get Y: buy trigger_qty of qualifying products, get cheapest/specific free or discounted.
     *
     * Conditions: qualifying_product_ids, trigger_qty
     * Actions: discount_type, discount_value (100% for free), applies_to
     *
     * @return array<int, PromotionDiscount>
     */
    private function evaluateBuyXGetY(Promotion $promotion, CartContext $cart): array
    {
        $conditions = $promotion->conditions;
        /** @var array<string> $qualifyingProductIds */
        $qualifyingProductIds = $conditions['qualifying_product_ids'] ?? [];
        $triggerQty = (int) ($conditions['trigger_qty'] ?? 0);
        /** @var array<string> $rewardProductIds */
        $rewardProductIds = $conditions['reward_product_ids'] ?? [];
        $rewardQty = max(1, (int) ($conditions['reward_qty'] ?? 1));

        if ($triggerQty <= 0 || count($qualifyingProductIds) === 0) {
            return [];
        }

        // Count qualifying items in cart
        $qualifyingItems = array_filter(
            $cart->items,
            fn (CartItemContext $item): bool => in_array($item->productId, $qualifyingProductIds, true)
        );

        $totalQualifyingQty = array_sum(array_map(fn (CartItemContext $i): int => $i->quantity, $qualifyingItems));

        $triggerCount = intdiv($totalQualifyingQty, $triggerQty);
        if ($triggerCount < 1) {
            return [];
        }

        // Determine reward target based on whether reward_product_ids is set
        if (count($rewardProductIds) > 0) {
            // Reward-product mode: discount applies to specific reward items
            $rewardItems = array_filter(
                $cart->items,
                fn (CartItemContext $item): bool => in_array($item->productId, $rewardProductIds, true)
            );

            if (count($rewardItems) === 0) {
                return [];
            }

            $rewardItem = $this->findCheapestItem($rewardItems);
            if ($rewardItem === null) {
                return [];
            }

            $availableRewardQty = array_sum(array_map(fn (CartItemContext $i): int => $i->quantity, $rewardItems));
            $totalRewards = min($triggerCount * $rewardQty, $availableRewardQty);
            $appliesTo = DiscountAppliesTo::SpecificItem;
        } else {
            // Backward-compatible mode: cheapest qualifying item
            $rewardItem = $this->findCheapestItem($qualifyingItems);
            if ($rewardItem === null) {
                return [];
            }

            $availableRewardQty = $rewardItem->quantity;
            $totalRewards = min($triggerCount * $rewardQty, $availableRewardQty);
            $appliesTo = DiscountAppliesTo::CheapestItem;
        }

        [$discountValue, $maxDiscount] = $this->getPromotionAmounts($promotion);

        $perUnitDiscount = $this->calculateDiscount(
            $promotion->discount_type,
            $discountValue,
            $rewardItem->unitPrice,
            $maxDiscount,
        );

        if (bccomp($perUnitDiscount, '0', $this->scale()) <= 0) {
            return [];
        }

        $totalDiscount = bcmul($perUnitDiscount, (string) $totalRewards, $this->scale());

        return [
            new PromotionDiscount(
                promotionId: $promotion->id,
                promotionName: $promotion->name,
                discountAmount: $totalDiscount,
                appliesTo: $appliesTo,
                targetProductId: $rewardItem->productId,
                isExclusive: $promotion->is_exclusive,
                stackingGroup: $promotion->stacking_group,
                priority: $promotion->priority,
            ),
        ];
    }

    /**
     * Volume Discount: discount applied when total quantity or amount exceeds threshold.
     *
     * Conditions: min_qty OR min_amount, qualifying_product_ids (optional)
     *
     * @return array<int, PromotionDiscount>
     */
    private function evaluateVolumeDiscount(Promotion $promotion, CartContext $cart): array
    {
        $conditions = $promotion->conditions;
        $minQty = (int) ($conditions['min_qty'] ?? 0);
        $minAmount = $this->toNumeric((string) ($conditions['min_amount'] ?? '0'), $this->scale());
        /** @var array<string> $qualifyingProductIds */
        $qualifyingProductIds = $conditions['qualifying_product_ids'] ?? [];

        // Filter to qualifying items (or all items if no filter)
        $targetItems = count($qualifyingProductIds) > 0
            ? array_filter($cart->items, fn (CartItemContext $i): bool => in_array($i->productId, $qualifyingProductIds, true))
            : $cart->items;

        $totalQty = array_sum(array_map(fn (CartItemContext $i): int => $i->quantity, $targetItems));
        /** @var numeric-string */
        $totalAmount = '0';
        foreach ($targetItems as $item) {
            $totalAmount = bcadd($totalAmount, $item->lineTotal, $this->scale());
        }

        // Check threshold
        if ($minQty > 0 && $totalQty < $minQty) {
            return [];
        }
        if (bccomp($minAmount, '0', $this->scale()) > 0 && bccomp($totalAmount, $minAmount, $this->scale()) < 0) {
            return [];
        }

        [$discountValue, $maxDiscount] = $this->getPromotionAmounts($promotion);

        $discountAmount = $this->calculateDiscount(
            $promotion->discount_type,
            $discountValue,
            $totalAmount,
            $maxDiscount,
        );

        if (bccomp($discountAmount, '0', $this->scale()) <= 0) {
            return [];
        }

        return [
            new PromotionDiscount(
                promotionId: $promotion->id,
                promotionName: $promotion->name,
                discountAmount: $discountAmount,
                appliesTo: $promotion->applies_to,
                targetProductId: null,
                isExclusive: $promotion->is_exclusive,
                stackingGroup: $promotion->stacking_group,
                priority: $promotion->priority,
            ),
        ];
    }

    /**
     * Category Discount: discount on all items in a category.
     *
     * Conditions: category_ids
     *
     * @return array<int, PromotionDiscount>
     */
    private function evaluateCategoryDiscount(Promotion $promotion, CartContext $cart): array
    {
        $conditions = $promotion->conditions;
        /** @var array<string> $categoryIds */
        $categoryIds = $conditions['category_ids'] ?? [];

        if (count($categoryIds) === 0) {
            return [];
        }

        $targetItems = array_filter(
            $cart->items,
            fn (CartItemContext $item): bool => $item->categoryId !== null && in_array($item->categoryId, $categoryIds, true)
        );

        if (count($targetItems) === 0) {
            return [];
        }

        /** @var numeric-string */
        $totalAmount = '0';
        foreach ($targetItems as $item) {
            $totalAmount = bcadd($totalAmount, $item->lineTotal, $this->scale());
        }

        [$discountValue, $maxDiscount] = $this->getPromotionAmounts($promotion);

        $discountAmount = $this->calculateDiscount(
            $promotion->discount_type,
            $discountValue,
            $totalAmount,
            $maxDiscount,
        );

        if (bccomp($discountAmount, '0', $this->scale()) <= 0) {
            return [];
        }

        return [
            new PromotionDiscount(
                promotionId: $promotion->id,
                promotionName: $promotion->name,
                discountAmount: $discountAmount,
                appliesTo: DiscountAppliesTo::QualifyingItems,
                targetProductId: null,
                isExclusive: $promotion->is_exclusive,
                stackingGroup: $promotion->stacking_group,
                priority: $promotion->priority,
            ),
        ];
    }

    /**
     * Combo Discount: discount when all required products are present.
     *
     * Conditions: combo_product_ids (all must be present)
     *
     * @return array<int, PromotionDiscount>
     */
    private function evaluateComboDiscount(Promotion $promotion, CartContext $cart): array
    {
        $conditions = $promotion->conditions;
        /** @var array<string> $comboProductIds */
        $comboProductIds = $conditions['combo_product_ids'] ?? [];

        if (count($comboProductIds) === 0) {
            return [];
        }

        $cartProductIds = array_map(fn (CartItemContext $i): string => $i->productId, $cart->items);

        // All combo products must be present in cart
        foreach ($comboProductIds as $requiredId) {
            if (! in_array($requiredId, $cartProductIds, true)) {
                return [];
            }
        }

        // Calculate combo subtotal
        $comboItems = array_filter(
            $cart->items,
            fn (CartItemContext $i): bool => in_array($i->productId, $comboProductIds, true)
        );

        /** @var numeric-string */
        $comboTotal = '0';
        foreach ($comboItems as $item) {
            $comboTotal = bcadd($comboTotal, $item->lineTotal, $this->scale());
        }

        [$discountValue, $maxDiscount] = $this->getPromotionAmounts($promotion);

        $discountAmount = $this->calculateDiscount(
            $promotion->discount_type,
            $discountValue,
            $comboTotal,
            $maxDiscount,
        );

        if (bccomp($discountAmount, '0', $this->scale()) <= 0) {
            return [];
        }

        return [
            new PromotionDiscount(
                promotionId: $promotion->id,
                promotionName: $promotion->name,
                discountAmount: $discountAmount,
                appliesTo: DiscountAppliesTo::QualifyingItems,
                targetProductId: null,
                isExclusive: $promotion->is_exclusive,
                stackingGroup: $promotion->stacking_group,
                priority: $promotion->priority,
            ),
        ];
    }

    /**
     * Calculate discount amount based on type and value.
     *
     * @param  numeric-string  $value
     * @param  numeric-string  $baseAmount
     * @param  numeric-string|null  $maxDiscount
     * @return numeric-string
     */
    private function calculateDiscount(
        DiscountType $type,
        string $value,
        string $baseAmount,
        ?string $maxDiscount,
    ): string {
        /** @var numeric-string */
        $discountAmount = match ($type) {
            DiscountType::Percentage => bcdiv(bcmul($baseAmount, $value, 4), '100', $this->scale()),
            DiscountType::Fixed => bcadd($value, '0', $this->scale()),
            DiscountType::FreeItem => $baseAmount,
        };

        // Cap at max discount
        if ($maxDiscount !== null && bccomp($discountAmount, $maxDiscount, $this->scale()) > 0) {
            $discountAmount = bcadd($maxDiscount, '0', $this->scale());
        }

        // Cap at base amount (discount cannot exceed what it's applied to)
        if (bccomp($discountAmount, $baseAmount, $this->scale()) > 0) {
            $discountAmount = bcadd($baseAmount, '0', $this->scale());
        }

        return $discountAmount;
    }

    /**
     * Normalize a model string value to a numeric-string for bcmath.
     *
     * @return numeric-string
     */
    private function toNumeric(string $value, int $scale = 4): string
    {
        /** @var numeric-string $normalized */
        $normalized = $value;

        return bcadd($normalized, '0', $scale);
    }

    /**
     * Get the promotion's discount value and max discount as numeric-strings.
     *
     * @return array{numeric-string, numeric-string|null}
     */
    private function getPromotionAmounts(Promotion $promotion): array
    {
        return [
            $this->toNumeric((string) $promotion->discount_value),
            $promotion->max_discount_amount !== null ? $this->toNumeric((string) $promotion->max_discount_amount, $this->scale()) : null,
        ];
    }

    /**
     * @param  array<CartItemContext>  $items
     */
    private function findCheapestItem(array $items): ?CartItemContext
    {
        $cheapest = null;
        foreach ($items as $item) {
            if ($cheapest === null || bccomp($item->unitPrice, $cheapest->unitPrice, $this->scale()) < 0) {
                $cheapest = $item;
            }
        }

        return $cheapest;
    }
}
