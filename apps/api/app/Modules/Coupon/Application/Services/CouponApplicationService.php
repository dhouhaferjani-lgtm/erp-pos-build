<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Application\Services;

use App\Modules\Coupon\Domain\Contracts\CouponValidatorContract;
use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Exceptions\CouponInvalidException;
use App\Modules\Coupon\Domain\Services\CouponValidationService;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;

final class CouponApplicationService implements CouponValidatorContract
{
    public function __construct(
        private readonly CouponValidationService $validationService,
    ) {}

    /**
     * @throws CouponInvalidException
     */
    public function validateAndCalculate(
        string $code,
        CartContext $cart,
        ?string $partnerId,
    ): PromotionDiscount {
        $coupon = Coupon::query()
            ->where('tenant_id', $cart->tenantId)
            ->forCompany($cart->companyId)
            ->byCode($code)
            ->first();

        if ($coupon === null) {
            throw CouponInvalidException::notFound($code);
        }

        return $this->validationService->validateAndCalculate($coupon, $cart, $partnerId);
    }

    /**
     * Record coupon usage after successful checkout.
     */
    public function recordUsage(
        string $couponId,
        string $receiptId,
        ?string $partnerId,
        string $discountAmount,
    ): void {
        $coupon = Coupon::findOrFail($couponId);

        $coupon->usages()->create([
            'receipt_id' => $receiptId,
            'partner_id' => $partnerId,
            'discount_amount' => $discountAmount,
            'used_at' => now(),
        ]);

        $coupon->increment('use_count');

        // Auto-exhaust if limit reached
        if ($coupon->max_uses !== null && $coupon->use_count >= $coupon->max_uses) {
            $coupon->update(['status' => \App\Modules\Coupon\Domain\Enums\CouponStatus::Exhausted]);
        }

        // Auto-exhaust single-use coupons
        if ($coupon->is_single_use) {
            $coupon->update(['status' => \App\Modules\Coupon\Domain\Enums\CouponStatus::Exhausted]);
        }
    }
}
