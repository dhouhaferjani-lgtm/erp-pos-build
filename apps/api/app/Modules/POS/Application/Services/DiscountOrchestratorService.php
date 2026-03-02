<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Coupon\Domain\Contracts\CouponValidatorContract;
use App\Modules\POS\Domain\Services\DiscountStackingService;
use App\Modules\POS\Domain\ValueObjects\DiscountBreakdown;
use App\Modules\POS\Domain\ValueObjects\DiscountLine;
use App\Modules\Promotion\Domain\Contracts\PromotionEvaluatorContract;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;

/**
 * Single resolution point for all discount sources.
 *
 * Collects discounts from:
 * 1. Manual discounts (cashier-applied)
 * 2. Automatic promotions
 * 3. Coupon codes
 * 4. Loyalty reward redemptions
 *
 * Then applies stacking rules via DiscountStackingService.
 */
final class DiscountOrchestratorService
{
    public function __construct(
        private readonly PromotionEvaluatorContract $promotionEvaluator,
        private readonly CouponValidatorContract $couponValidator,
        private readonly DiscountStackingService $stackingService,
    ) {}

    /**
     * Resolve all discount sources for a cart.
     *
     * @param  numeric-string|null  $manualDiscountAmount  Cashier-applied discount
     * @param  numeric-string|null  $loyaltyDiscountAmount  From reward redemption
     */
    public function resolve(
        CartContext $cart,
        ?string $manualDiscountAmount = null,
        ?string $manualDiscountReason = null,
        ?string $couponCode = null,
        ?string $customerId = null,
        ?string $loyaltyDiscountAmount = null,
        ?string $loyaltyRewardId = null,
    ): DiscountBreakdown {
        $candidates = [];

        // 1. Manual discount (cashier-applied)
        if ($manualDiscountAmount !== null && bccomp($manualDiscountAmount, '0', 2) > 0) {
            $candidates[] = new DiscountLine(
                source: 'manual',
                stackingGroup: 'manual',
                isExclusive: false,
                priority: 100,
                discountAmount: $manualDiscountAmount,
                label: $manualDiscountReason ?? 'Manual discount',
                referenceId: null,
                appliesTo: 'transaction',
            );
        }

        // 2. Automatic promotions
        try {
            $promotionDiscounts = $this->promotionEvaluator->evaluateCart($cart);
            foreach ($promotionDiscounts as $promoDiscount) {
                $candidates[] = $this->fromPromotionDiscount($promoDiscount, 'promotion');
            }
        } catch (\Throwable) {
            // Promotion evaluation failure should not block checkout
        }

        // 3. Coupon code
        if ($couponCode !== null) {
            try {
                $couponDiscount = $this->couponValidator->validateAndCalculate(
                    $couponCode,
                    $cart,
                    $customerId,
                );
                if ($couponDiscount !== null) {
                    $candidates[] = $this->fromPromotionDiscount($couponDiscount, 'coupon');
                }
            } catch (\Throwable) {
                // Invalid coupon should not block checkout
            }
        }

        // 4. Loyalty reward redemption
        if ($loyaltyDiscountAmount !== null && bccomp($loyaltyDiscountAmount, '0', 2) > 0) {
            $candidates[] = new DiscountLine(
                source: 'loyalty',
                stackingGroup: 'loyalty',
                isExclusive: false,
                priority: 50,
                discountAmount: $loyaltyDiscountAmount,
                label: 'Loyalty reward',
                referenceId: $loyaltyRewardId,
                appliesTo: 'transaction',
            );
        }

        return $this->stackingService->resolve($candidates, $cart->subtotal);
    }

    /**
     * @param  'manual'|'promotion'|'coupon'|'loyalty'  $source
     */
    private function fromPromotionDiscount(PromotionDiscount $discount, string $source): DiscountLine
    {
        $appliesTo = match ($discount->appliesTo) {
            DiscountAppliesTo::Transaction => 'transaction',
            DiscountAppliesTo::QualifyingItems,
            DiscountAppliesTo::SpecificItem,
            DiscountAppliesTo::CheapestItem => $discount->targetProductId !== null
                ? "line:{$discount->targetProductId}"
                : 'transaction',
        };

        /** @var numeric-string $discountAmount */
        $discountAmount = $discount->discountAmount;

        return new DiscountLine(
            source: $source,
            stackingGroup: $discount->stackingGroup,
            isExclusive: $discount->isExclusive,
            priority: $discount->priority,
            discountAmount: $discountAmount,
            label: $discount->promotionName,
            referenceId: $discount->promotionId,
            appliesTo: $appliesTo,
        );
    }
}
