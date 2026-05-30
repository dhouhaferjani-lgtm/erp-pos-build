<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Coupon\Presentation\Requests\StoreCouponRequest;
use App\Modules\Coupon\Presentation\Requests\ValidateCouponRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.10 — Coupon module ingress precision ceiling tests.
 *
 * These tests bind to the REAL production FormRequest rules (StoreCouponRequest
 * and ValidateCouponRequest) so they FAIL if someone changes a production
 * decimal scale to the wrong value. StoreCouponRequest only needs a company id
 * set on CompanyContext (no DB lookup).
 */
final class IngressPrecisionTest extends TestCase
{
    /**
     * Production rules from StoreCouponRequest. requireCompanyId() only needs the
     * id to be set, so no DB row is required.
     *
     * @return array<string, mixed>
     */
    private function storeCouponRules(): array
    {
        $context = app(CompanyContext::class);
        $context->setCompanyId('00000000-0000-0000-0000-000000000001');

        return (new StoreCouponRequest($context))->rules();
    }

    /**
     * Production rules from ValidateCouponRequest (no DI dependencies).
     *
     * @return array<string, mixed>
     */
    private function validateCouponRules(): array
    {
        return (new ValidateCouponRequest)->rules();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $payload
     */
    private function fieldPasses(array $rules, string $field, array $payload): bool
    {
        $this->assertArrayHasKey(
            $field,
            $rules,
            "Field {$field} is missing from the production request rules — the test no longer binds to production."
        );

        return Validator::make($payload, [$field => $rules[$field]])->errors()->get($field) === [];
    }

    // ── StoreCoupon: discount_value (decimal 12,4) ───────────────────────────

    /** @dataProvider overPreciseDiscountValueProvider */
    public function test_discount_value_rejects_over_precise(string $value): void
    {
        $rules = $this->storeCouponRules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'discount_value', ['discount_value' => $value]),
            "Expected discount_value={$value} to fail"
        );
    }

    /** @dataProvider validDiscountValueProvider */
    public function test_discount_value_accepts_valid(string $value): void
    {
        $rules = $this->storeCouponRules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'discount_value', ['discount_value' => $value]),
            "Expected discount_value={$value} to pass"
        );
    }

    /** @return array<string, array{string}> */
    public static function overPreciseDiscountValueProvider(): array
    {
        return [
            '5-decimal' => ['10.12345'],
            '6-decimal' => ['10.123456'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validDiscountValueProvider(): array
    {
        return [
            'integer' => ['10'],
            '2-decimal-pct' => ['10.50'],
            '3-decimal' => ['10.123'],
            '4-decimal' => ['10.1234'],
        ];
    }

    // ── StoreCoupon: max_discount_amount (money scale 3) ─────────────────────

    public function test_max_discount_amount_rejects_4_decimal(): void
    {
        $rules = $this->storeCouponRules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'max_discount_amount', ['max_discount_amount' => '50.1234']),
            'Expected 50.1234 to fail'
        );
    }

    public function test_max_discount_amount_accepts_3_decimal(): void
    {
        $rules = $this->storeCouponRules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'max_discount_amount', ['max_discount_amount' => '50.123']),
            'Expected 50.123 to pass'
        );
    }

    // ── StoreCoupon: minimum_order_amount (money scale 3) ────────────────────

    public function test_minimum_order_amount_rejects_4_decimal(): void
    {
        $rules = $this->storeCouponRules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'minimum_order_amount', ['minimum_order_amount' => '100.1234']),
            'Expected 100.1234 to fail'
        );
    }

    public function test_minimum_order_amount_accepts_3_decimal(): void
    {
        $rules = $this->storeCouponRules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'minimum_order_amount', ['minimum_order_amount' => '100.123']),
            'Expected 100.123 to pass'
        );
    }

    // ── ValidateCoupon: items.*.quantity (integral — coupon counting) ────────
    //
    // F-COUPON-QTY: fractional coupon quantities are deferred (the coupon
    // consumer casts (int)), so the rule is integer/min:1 — NOT decimal.

    public function test_item_quantity_rejects_fractional(): void
    {
        $rules = $this->validateCouponRules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'items.*.quantity', ['items' => [['quantity' => '1.5']]]),
            'Expected fractional item quantity 1.5 to fail (coupon quantity is integral)'
        );
    }

    public function test_item_quantity_accepts_positive_integer(): void
    {
        $rules = $this->validateCouponRules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'items.*.quantity', ['items' => [['quantity' => 2]]]),
            'Expected integer item quantity 2 to pass'
        );
    }

    public function test_item_quantity_rejects_zero(): void
    {
        $rules = $this->validateCouponRules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'items.*.quantity', ['items' => [['quantity' => 0]]]),
            'Expected 0 to fail (min:1)'
        );
    }

    // ── ValidateCoupon: subtotal (money scale 3) ─────────────────────────────

    public function test_subtotal_rejects_4_decimal(): void
    {
        $rules = $this->validateCouponRules();

        $this->assertFalse(
            $this->fieldPasses($rules, 'subtotal', ['subtotal' => '99.1234']),
            'Expected 99.1234 to fail'
        );
    }

    public function test_subtotal_accepts_3_decimal(): void
    {
        $rules = $this->validateCouponRules();

        $this->assertTrue(
            $this->fieldPasses($rules, 'subtotal', ['subtotal' => '99.123']),
            'Expected 99.123 to pass'
        );
    }
}
