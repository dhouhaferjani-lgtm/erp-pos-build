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
use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * Pure domain validation of coupon rules against cart.
 */
final class CouponValidationService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

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

        // Global usage cap — Lane Q-4. Enforced HERE, where the discount is
        // granted, instead of only by the post-hoc auto-exhaust in
        // CouponApplicationService::recordUsage. Placed ahead of isValid()
        // because isValid() folds the cap into its boolean and would surface a
        // capped-but-in-date coupon as "expired" to the cashier.
        if ($coupon->max_uses !== null && $coupon->use_count >= $coupon->max_uses) {
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
            $minOrderAmount = bcadd($rawMinOrder, '0', $this->scale());
            if (bccomp($cart->subtotal, $minOrderAmount, $this->scale()) < 0) {
                throw CouponInvalidException::minimumNotMet(
                    $coupon->code,
                    $coupon->minimum_order_amount,
                );
            }
        }

        // Calculate discount amount
        /** @var numeric-string $rawDiscount */
        $rawDiscount = (string) $coupon->discount_value;
        /** @var numeric-string $discountValue */
        $discountValue = bcadd($rawDiscount, '0', 4);

        $discountType = DiscountType::tryFrom($coupon->discount_type);
        if ($discountType === null) {
            $discountType = DiscountType::Percentage;
        }

        /** @var numeric-string $discountAmount */
        $discountAmount = match ($discountType) {
            DiscountType::Percentage => bcdiv(bcmul($cart->subtotal, $discountValue, 4), '100', $this->scale()),
            DiscountType::Fixed => bcadd($discountValue, '0', $this->scale()),
            DiscountType::FreeItem => '0.00',
        };

        // Cap at max discount
        if ($coupon->max_discount_amount !== null) {
            /** @var numeric-string $rawMaxDiscount */
            $rawMaxDiscount = $coupon->max_discount_amount;
            /** @var numeric-string $maxDiscount */
            $maxDiscount = bcadd($rawMaxDiscount, '0', $this->scale());
            if (bccomp($discountAmount, $maxDiscount, $this->scale()) > 0) {
                $discountAmount = $maxDiscount;
            }
        }

        // Cap at subtotal
        if (bccomp($discountAmount, $cart->subtotal, $this->scale()) > 0) {
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

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }
}
