<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Application\Services;

use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Enums\CouponStatus;

final class CouponManagementService
{
    /**
     * Revoke a coupon (any status → Revoked).
     */
    public function revoke(Coupon $coupon): Coupon
    {
        if ($coupon->status === CouponStatus::Revoked) {
            throw new \DomainException('Coupon is already revoked.');
        }

        $coupon->update(['status' => CouponStatus::Revoked]);

        return $coupon;
    }

    /**
     * Reactivate a revoked or exhausted coupon.
     */
    public function reactivate(Coupon $coupon): Coupon
    {
        if ($coupon->status === CouponStatus::Active) {
            throw new \DomainException('Coupon is already active.');
        }

        $coupon->update(['status' => CouponStatus::Active]);

        return $coupon;
    }
}
