<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Domain\Contracts;

use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;

interface CouponValidatorContract
{
    /**
     * Validate a coupon code and calculate the resulting discount.
     *
     * @return PromotionDiscount|null Null if coupon is invalid
     *
     * @throws \App\Modules\Coupon\Domain\Exceptions\CouponInvalidException
     */
    public function validateAndCalculate(
        string $code,
        CartContext $cart,
        ?string $partnerId,
    ): ?PromotionDiscount;
}
