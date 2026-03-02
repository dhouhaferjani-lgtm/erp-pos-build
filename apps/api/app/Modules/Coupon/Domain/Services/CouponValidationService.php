<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Domain\Services;

use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Enums\CouponStatus;
use App\Modules\Coupon\Domain\Exceptions\CouponInvalidException;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;

/**
 * Pure domain validation of coupon rules against cart.
 */
final class CouponValidationService
{
    /**
     * Validate and calculate the discount for a coupon.
     *
     * @throws CouponInvalidException
     */
    public function validateAndCalculate(
        Coupon $coupon,
        CartContext $cart,
        ?string $partnerId,
    ): PromotionDiscount {
        // Status check
        if ($coupon->status === CouponStatus::Revoked) {
            throw CouponInvalidException::revoked($coupon->code);
        }
        if ($coupon->status === CouponStatus::Expired) {
            throw CouponInvalidException::expired($coupon->code);
        }
        if ($coupon->status === CouponStatus::Exhausted) {
            throw CouponInvalidException::exhausted($coupon->code);
        }

        // Date validation
        if (! $coupon->isValid()) {
            throw CouponInvalidException::expired($coupon->code);
        }

        // Per-customer limit
        if ($partnerId !== null && $coupon->max_uses_per_customer !== null) {
            $customerUses = $coupon->customerUsageCount($partnerId);
            if ($customerUses >= $coupon->max_uses_per_customer) {
                throw CouponInvalidException::customerLimitReached($coupon->code);
            }
        }

        // Minimum order amount
        if ($coupon->minimum_order_amount !== null) {
            /** @var numeric-string $rawMinOrder */
            $rawMinOrder = $coupon->minimum_order_amount;
            /** @var numeric-string $minOrderAmount */
            $minOrderAmount = bcadd($rawMinOrder, '0', 2);
            if (bccomp($cart->subtotal, $minOrderAmount, 2) < 0) {
                throw CouponInvalidException::minimumNotMet(
                    $coupon->code,
                    $coupon->minimum_order_amount,
                );
            }
        }

        // Calculate discount amount
        /** @var numeric-string $rawDiscountValue */
        $rawDiscountValue = $coupon->discount_value;
        /** @var numeric-string $discountValue */
        $discountValue = bcadd($rawDiscountValue, '0', 4);

        $discountType = DiscountType::tryFrom($coupon->discount_type);
        if ($discountType === null) {
            $discountType = DiscountType::Percentage;
        }

        /** @var numeric-string $discountAmount */
        $discountAmount = match ($discountType) {
            DiscountType::Percentage => bcdiv(bcmul($cart->subtotal, $discountValue, 4), '100', 2),
            DiscountType::Fixed => bcadd($discountValue, '0', 2),
            DiscountType::FreeItem => '0.00',
        };

        // Cap at max discount
        if ($coupon->max_discount_amount !== null) {
            /** @var numeric-string $rawMaxDiscount */
            $rawMaxDiscount = $coupon->max_discount_amount;
            /** @var numeric-string $maxDiscount */
            $maxDiscount = bcadd($rawMaxDiscount, '0', 2);
            if (bccomp($discountAmount, $maxDiscount, 2) > 0) {
                $discountAmount = $maxDiscount;
            }
        }

        // Cap at subtotal
        if (bccomp($discountAmount, $cart->subtotal, 2) > 0) {
            $discountAmount = $cart->subtotal;
        }

        return new PromotionDiscount(
            promotionId: $coupon->id,
            promotionName: "Coupon: {$coupon->code}",
            discountAmount: $discountAmount,
            appliesTo: DiscountAppliesTo::Transaction,
            targetProductId: null,
            isExclusive: $coupon->is_exclusive,
            stackingGroup: $coupon->stacking_group,
            priority: 0,
        );
    }
}
