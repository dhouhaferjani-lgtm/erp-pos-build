<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Coupon\Domain\Contracts\CouponValidatorContract;
use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Enums\CouponStatus;
use App\Modules\Coupon\Domain\Exceptions\CouponInvalidException;
use App\Modules\Coupon\Domain\Services\CouponValidationService;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CouponApplicationService implements CouponValidatorContract
{
    public function __construct(
        private readonly CouponValidationService $validationService,
        private readonly CompanyContext $companyContext,
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
     *
     * api.unmapped.017 (api.pricing): the lookup is scoped by the caller's
     * CompanyContext (tenant_id + company_id). A cross-tenant couponId
     * triggers ModelNotFoundException before any usage row is created or
     * use_count is mutated. Mirrors the api.pricing service-tier
     * defense-in-depth pattern (PricingService::getPrice).
     *
     * Lane Q-4 (POS-till sub-report HIGH): validation happens at cart time and
     * this write happens after the receipt is sealed, so the two are separated
     * by the whole checkout. Everything below therefore runs inside ONE
     * transaction with the coupon row claimed by `lockForUpdate()`:
     *
     *  - a replay of an already-recorded receipt (POS sync retry) is a no-op —
     *    it must neither inflate `use_count` nor 500 the sync;
     *  - the cap and the status are RE-CHECKED under the lock, so two
     *    concurrent checkouts on a `max_uses = 1` coupon serialise and the
     *    loser is refused instead of both being honoured;
     *  - a 23505 from the `uniq_coupon_usages_coupon_receipt` index (the
     *    replay that slipped past the `exists()` probe) is swallowed as the
     *    same idempotent outcome.
     *
     * @throws CouponInvalidException when the coupon is no longer redeemable
     */
    public function recordUsage(
        string $couponId,
        string $receiptId,
        ?string $partnerId,
        string $discountAmount,
    ): void {
        $company = $this->companyContext->requireCompany();

        DB::transaction(function () use ($couponId, $receiptId, $partnerId, $discountAmount, $company): void {
            /** @var Coupon $coupon */
            $coupon = Coupon::query()
                ->where('tenant_id', $company->tenant_id)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->findOrFail($couponId);

            // Idempotency FIRST: a replayed receipt is the same fiscal event and
            // must be accepted even once the coupon has auto-exhausted itself.
            if ($coupon->usages()->where('receipt_id', $receiptId)->exists()) {
                return;
            }

            $this->assertRedeemableUnderLock($coupon);

            try {
                // Nested transaction => SAVEPOINT. Letting the exception escape
                // this closure is what rolls the savepoint back; catching it
                // inside would leave an enclosing checkout transaction poisoned.
                DB::transaction(function () use ($coupon, $receiptId, $partnerId, $discountAmount): void {
                    $coupon->usages()->create([
                        'receipt_id' => $receiptId,
                        'partner_id' => $partnerId,
                        'discount_amount' => $discountAmount,
                        'used_at' => now(),
                    ]);
                });
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                // The unique index caught the replay we did not observe. Same
                // outcome as the exists() short-circuit above: no counter move.
                return;
            }

            $coupon->increment('use_count');

            // Auto-exhaust if limit reached
            if ($coupon->max_uses !== null && $coupon->use_count >= $coupon->max_uses) {
                $coupon->update(['status' => CouponStatus::Exhausted]);
            }

            // Auto-exhaust single-use coupons
            if ($coupon->is_single_use) {
                $coupon->update(['status' => CouponStatus::Exhausted]);
            }
        });
    }

    /**
     * Re-check, under the row lock, that this coupon may still be spent.
     *
     * Deliberately mirrors CouponValidationService's refusal idiom so the POS
     * surfaces the same message whether the coupon dies at cart time or between
     * cart time and sealing.
     *
     * @throws CouponInvalidException
     */
    private function assertRedeemableUnderLock(Coupon $coupon): void
    {
        if ($coupon->status === CouponStatus::Revoked) {
            throw CouponInvalidException::revoked($coupon->code);
        }
        if ($coupon->status === CouponStatus::Expired) {
            throw CouponInvalidException::expired($coupon->code);
        }
        if ($coupon->status === CouponStatus::Exhausted) {
            throw CouponInvalidException::exhausted($coupon->code);
        }

        if ($coupon->max_uses !== null && $coupon->use_count >= $coupon->max_uses) {
            // The counter reached the cap without the status catching up
            // (legacy rows, or a concurrent writer that lost the race). Seal it
            // here so the next reader short-circuits on status alone.
            $coupon->update(['status' => CouponStatus::Exhausted]);

            throw CouponInvalidException::exhausted($coupon->code);
        }
    }

    /**
     * PostgreSQL 23505 (unique_violation) / SQLite "UNIQUE constraint failed".
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        if (($e->errorInfo[0] ?? null) === '23505') {
            return true;
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
