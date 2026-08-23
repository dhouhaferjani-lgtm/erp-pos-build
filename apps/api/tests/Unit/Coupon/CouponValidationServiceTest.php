<?php

declare(strict_types=1);

namespace Tests\Unit\Coupon;

use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Enums\CouponStatus;
use App\Modules\Coupon\Domain\Enums\CouponType;
use App\Modules\Coupon\Domain\Exceptions\CouponInvalidException;
use App\Modules\Coupon\Domain\Services\CouponValidationService;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\CartItemContext;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CouponValidationServiceTest extends TestCase
{
    /**
     * Frozen wall-clock used by every test in this file. Pinning this prevents
     * "now-relative" fixtures (Carbon::now()->subDay() etc.) from drifting into
     * coupon start/expiry boundaries on certain calendar days. The exact value
     * is arbitrary as long as it's stable.
     */
    private const FROZEN_NOW = '2026-04-15 12:00:00';

    private CouponValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the global Carbon clock so any code path reading `Carbon::now()`
        // (Coupon::isValid(), CartContext::appliedAt, etc.) sees a deterministic
        // instant regardless of when the suite runs.
        CarbonImmutable::setTestNow(self::FROZEN_NOW);
        Carbon::setTestNow(self::FROZEN_NOW);

        $scaleResolver = $this->createMock(CurrencyScaleResolverInterface::class);
        $scaleResolver->method('getScale')->willReturn(2);
        $this->service = new CouponValidationService($scaleResolver);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeCart(array $items = [], string $subtotal = '100.00'): CartContext
    {
        if (empty($items)) {
            $items = [
                new CartItemContext(
                    productId: 'prod-1',
                    categoryId: 'cat-1',
                    quantity: 2,
                    unitPrice: '50.00',
                    lineTotal: '100.00',
                ),
            ];
        }

        return new CartContext(
            tenantId: 'tenant-1',
            companyId: 'company-1',
            items: $items,
            subtotal: $subtotal,
            appliedAt: Carbon::now()->toIso8601String(),
        );
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        $coupon = new Coupon;
        $coupon->setRawAttributes(array_merge([
            'id' => 'coupon-1',
            'tenant_id' => 'tenant-1',
            'company_id' => 'company-1',
            'name' => 'Test Coupon',
            'code' => 'TEST10',
            'type' => CouponType::Standard->value,
            'status' => CouponStatus::Active->value,
            'is_single_use' => false,
            'max_uses' => null,
            'use_count' => 0,
            'max_uses_per_customer' => null,
            'discount_type' => 'percentage',
            'discount_value' => '10.0000',
            'max_discount_amount' => null,
            'minimum_order_amount' => null,
            'is_exclusive' => false,
            'stacking_group' => 'coupons',
            'starts_at' => null,
            'expires_at' => null,
        ], $overrides));

        return $coupon;
    }

    // -- Percentage discount tests --

    public function test_percentage_discount_calculates_correctly(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => 'percentage',
            'discount_value' => '10.0000',
        ]);

        $result = $this->service->validateAndCalculate($coupon, $this->makeCart(), null);

        $this->assertEquals('10.00', $result->discountAmount);
        $this->assertEquals(DiscountAppliesTo::Transaction, $result->appliesTo);
        $this->assertStringContainsString('TEST10', $result->promotionName);
    }

    public function test_percentage_discount_capped_at_max(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => 'percentage',
            'discount_value' => '50.0000',
            'max_discount_amount' => '20.00',
        ]);

        $result = $this->service->validateAndCalculate($coupon, $this->makeCart(), null);

        $this->assertEquals('20.00', $result->discountAmount);
    }

    public function test_percentage_discount_capped_at_subtotal(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => 'percentage',
            'discount_value' => '100.0000',
        ]);
        $cart = $this->makeCart(subtotal: '50.00');

        $result = $this->service->validateAndCalculate($coupon, $cart, null);

        $this->assertEquals('50.00', $result->discountAmount);
    }

    // -- Fixed discount tests --

    public function test_fixed_discount_calculates_correctly(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => 'fixed',
            'discount_value' => '15.0000',
        ]);

        $result = $this->service->validateAndCalculate($coupon, $this->makeCart(), null);

        $this->assertEquals('15.00', $result->discountAmount);
    }

    public function test_fixed_discount_capped_at_subtotal(): void
    {
        $coupon = $this->makeCoupon([
            'discount_type' => 'fixed',
            'discount_value' => '200.0000',
        ]);
        $cart = $this->makeCart(subtotal: '80.00');

        $result = $this->service->validateAndCalculate($coupon, $cart, null);

        $this->assertEquals('80.00', $result->discountAmount);
    }

    // -- Status validation tests --

    public function test_revoked_coupon_throws(): void
    {
        $coupon = $this->makeCoupon(['status' => CouponStatus::Revoked->value]);

        $this->expectException(CouponInvalidException::class);
        $this->expectExceptionMessage('revoked');

        $this->service->validateAndCalculate($coupon, $this->makeCart(), null);
    }

    public function test_expired_status_throws(): void
    {
        $coupon = $this->makeCoupon(['status' => CouponStatus::Expired->value]);

        $this->expectException(CouponInvalidException::class);
        $this->expectExceptionMessage('expired');

        $this->service->validateAndCalculate($coupon, $this->makeCart(), null);
    }

    public function test_exhausted_coupon_throws(): void
    {
        $coupon = $this->makeCoupon(['status' => CouponStatus::Exhausted->value]);

        $this->expectException(CouponInvalidException::class);
        $this->expectExceptionMessage('fully redeemed');

        $this->service->validateAndCalculate($coupon, $this->makeCart(), null);
    }

    // -- Date validation tests --

    public function test_coupon_not_yet_started_throws(): void
    {
        $coupon = $this->makeCoupon([
            'starts_at' => Carbon::now()->addDay()->toDateTimeString(),
        ]);

        $this->expectException(CouponInvalidException::class);

        $this->service->validateAndCalculate($coupon, $this->makeCart(), null);
    }

    public function test_coupon_past_expiry_throws(): void
    {
        $coupon = $this->makeCoupon([
            'expires_at' => Carbon::now()->subDay()->toDateTimeString(),
        ]);

        $this->expectException(CouponInvalidException::class);

        $this->service->validateAndCalculate($coupon, $this->makeCart(), null);
    }

    public function test_coupon_within_date_range_succeeds(): void
    {
        $coupon = $this->makeCoupon([
            'starts_at' => Carbon::now()->subDay()->toDateTimeString(),
            'expires_at' => Carbon::now()->addDay()->toDateTimeString(),
        ]);

        $result = $this->service->validateAndCalculate($coupon, $this->makeCart(), null);

        $this->assertEquals('10.00', $result->discountAmount);
    }

    // -- Usage limit tests --

    public function test_usage_limit_reached_throws(): void
    {
        $coupon = $this->makeCoupon([
            'max_uses' => 5,
            'use_count' => 5,
        ]);

        $this->expectException(CouponInvalidException::class);

        $this->service->validateAndCalculate($coupon, $this->makeCart(), null);
    }

    /**
     * Lane Q-4: the global cap must produce the *exhausted* refusal, not the
     * date-range "expired" one it fell through to via Coupon::isValid(). The
     * message is what the POS surfaces to the cashier, and "expired" on a
     * coupon that is still inside its validity window is a support ticket.
     */
    public function test_usage_limit_reached_throws_the_exhausted_refusal(): void
    {
        $coupon = $this->makeCoupon([
            'max_uses' => 5,
            'use_count' => 5,
            'starts_at' => Carbon::now()->subDay()->toDateTimeString(),
            'expires_at' => Carbon::now()->addDay()->toDateTimeString(),
        ]);

        $this->expectException(CouponInvalidException::class);
        $this->expectExceptionMessage('fully redeemed');

        $this->service->validateAndCalculate($coupon, $this->makeCart(), null);
    }

    public function test_usage_limit_not_yet_reached_still_applies(): void
    {
        $coupon = $this->makeCoupon([
            'max_uses' => 5,
            'use_count' => 4,
        ]);

        $result = $this->service->validateAndCalculate($coupon, $this->makeCart(), null);

        $this->assertEquals('10.00', $result->discountAmount);
    }

    // -- Minimum order amount tests --

    public function test_minimum_order_amount_not_met_throws(): void
    {
        $coupon = $this->makeCoupon([
            'minimum_order_amount' => '150.00',
        ]);

        $this->expectException(CouponInvalidException::class);
        $this->expectExceptionMessage('minimum');

        $this->service->validateAndCalculate($coupon, $this->makeCart(subtotal: '100.00'), null);
    }

    public function test_minimum_order_amount_met_succeeds(): void
    {
        $coupon = $this->makeCoupon([
            'minimum_order_amount' => '50.00',
        ]);

        $result = $this->service->validateAndCalculate($coupon, $this->makeCart(subtotal: '100.00'), null);

        $this->assertEquals('10.00', $result->discountAmount);
    }

    // -- Stacking metadata tests --

    public function test_exclusive_coupon_returns_exclusive_flag(): void
    {
        $coupon = $this->makeCoupon([
            'is_exclusive' => true,
            'stacking_group' => 'vip',
        ]);

        $result = $this->service->validateAndCalculate($coupon, $this->makeCart(), null);

        $this->assertTrue($result->isExclusive);
        $this->assertEquals('vip', $result->stackingGroup);
    }
}
