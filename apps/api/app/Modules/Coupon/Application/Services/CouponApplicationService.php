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
    /**
     * The unique index created by
     * database/migrations/tenant/2026_08_23_000500_unique_coupon_and_promotion_usage_per_receipt.php.
     * Only a 23505 naming THIS index means "this receipt is already recorded".
     */
    private const USAGE_RECEIPT_UNIQUE_INDEX = 'uniq_coupon_usages_coupon_receipt';

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
     * CALLER CONTRACT — read before wiring this into checkout (gate r1 F-4).
     * This method runs POST-SEAL: by the time it is called the discount is
     * already on a sealed, hash-chained fiscal receipt, so a refusal here can
     * never un-grant it. Therefore:
     *  - the caller MUST catch `CouponInvalidException` and MUST NOT let it roll
     *    back or fail the sealed receipt — the sale is final, only the coupon
     *    bookkeeping is in question;
     *  - a queued/sync caller MUST NOT retry a refusal indefinitely: the refusal
     *    is deterministic, so an un-caught throw becomes a poison message that
     *    blocks the sync queue behind it;
     *  - a replay of an already-recorded receipt returns SUCCESS (no exception),
     *    so "no exception" does not imply "counter moved".
     * Recommended future contract is record-and-flag: always write the usage row
     * and raise an over-cap exception report for the back office, instead of
     * refusing a discount that was already given. That is an OWNER RULING owed
     * before any wiring — this method deliberately does NOT implement it yet,
     * because today it has zero callers and refusing is the conservative default.
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

        // The closure RETURNS a refusal rather than throwing it, so that any
        // write it made under the lock (the Exhausted seal below) COMMITS. A
        // throw from inside would unwind the very transaction the seal lives in
        // and the seal would be silently rolled back every time (gate r1 F-1).
        $refusal = DB::transaction(
            function () use ($couponId, $receiptId, $partnerId, $discountAmount, $company): ?CouponInvalidException {
                /** @var Coupon $coupon */
                $coupon = Coupon::query()
                    ->where('tenant_id', $company->tenant_id)
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->findOrFail($couponId);

                // Idempotency FIRST: a replayed receipt is the same fiscal event
                // and must be accepted even once the coupon auto-exhausted.
                if ($coupon->usages()->where('receipt_id', $receiptId)->exists()) {
                    return null;
                }

                $refusal = $this->refuseUnderLock($coupon);
                if ($refusal !== null) {
                    return $refusal;
                }

                try {
                    // Nested transaction => SAVEPOINT. Letting the exception
                    // escape this closure is what rolls the savepoint back;
                    // catching it inside would leave an enclosing checkout
                    // transaction poisoned (PostgreSQL 25P02).
                    DB::transaction(function () use ($coupon, $receiptId, $partnerId, $discountAmount): void {
                        $coupon->usages()->create([
                            'receipt_id' => $receiptId,
                            'partner_id' => $partnerId,
                            'discount_amount' => $discountAmount,
                            'used_at' => now(),
                        ]);
                    });
                } catch (QueryException $e) {
                    if (! $this->isUsageReceiptUniqueViolation($e)) {
                        throw $e;
                    }

                    // The unique index caught the replay we did not observe.
                    // Same outcome as the exists() short-circuit: no counter move.
                    return null;
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

                return null;
            }
        );

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /**
     * Decide, under the row lock, whether this coupon may still be spent.
     *
     * Returns the refusal instead of throwing it so the caller can commit the
     * transaction (and with it the Exhausted seal) BEFORE the exception unwinds
     * the stack. Deliberately mirrors CouponValidationService's refusal idiom so
     * the POS surfaces the same message whether the coupon dies at cart time or
     * between cart time and sealing.
     */
    private function refuseUnderLock(Coupon $coupon): ?CouponInvalidException
    {
        if ($coupon->status === CouponStatus::Revoked) {
            return CouponInvalidException::revoked($coupon->code);
        }
        if ($coupon->status === CouponStatus::Expired) {
            return CouponInvalidException::expired($coupon->code);
        }
        if ($coupon->status === CouponStatus::Exhausted) {
            return CouponInvalidException::exhausted($coupon->code);
        }

        if ($coupon->max_uses !== null && $coupon->use_count >= $coupon->max_uses) {
            // The counter reached the cap without the status catching up
            // (legacy rows, a data fix, or a writer that lost the race and never
            // got to auto-exhaust). Seal it so the next reader short-circuits on
            // status alone. This write COMMITS because we return the refusal
            // rather than throwing it here.
            $coupon->update(['status' => CouponStatus::Exhausted]);

            return CouponInvalidException::exhausted($coupon->code);
        }

        return null;
    }

    /**
     * True only for a PostgreSQL 23505 raised by OUR (coupon_id, receipt_id)
     * index — i.e. a replayed receipt.
     *
     * Narrowed per gate r1 F-7: a blanket "any unique violation" test would
     * silently swallow an unrelated constraint failure (a future index on
     * coupon_usages, a trigger-side insert) and report the usage as recorded
     * when nothing was written. Anything else is rethrown.
     */
    private function isUsageReceiptUniqueViolation(QueryException $e): bool
    {
        if (($e->errorInfo[0] ?? null) !== '23505') {
            return false;
        }

        return str_contains($e->getMessage(), self::USAGE_RECEIPT_UNIQUE_INDEX);
    }
}
