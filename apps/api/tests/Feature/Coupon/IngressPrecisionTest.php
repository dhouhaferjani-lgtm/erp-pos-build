<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.10 — Coupon module ingress precision ceiling tests.
 *
 * Verifies over-precise values are rejected and within-scale values are accepted.
 * Uses Validator::make() against the rule arrays directly.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── discount_value (decimal 12,4 — shared by fixed-money and percent) ───

    /** @dataProvider overPreciseDiscountValueProvider */
    public function test_discount_value_rejects_over_precise(string $value): void
    {
        $rules = ['discount_value' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['discount_value' => $value], $rules);

        $this->assertTrue($v->fails(), 'Expected discount_value='.$value.' to fail');
        $this->assertArrayHasKey('discount_value', $v->errors()->toArray());
    }

    /** @dataProvider validDiscountValueProvider */
    public function test_discount_value_accepts_valid(string $value): void
    {
        $rules = ['discount_value' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['discount_value' => $value], $rules);

        $this->assertEmpty(
            $v->errors()->get('discount_value'),
            'Expected discount_value='.$value.' to pass: '.$v->errors()->first('discount_value')
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

    // ── max_discount_amount (money scale 3) ──────────────────────────────────

    public function test_max_discount_amount_rejects_4_decimal(): void
    {
        $rules = ['max_discount_amount' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['max_discount_amount' => '50.1234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 50.1234 to fail');
        $this->assertArrayHasKey('max_discount_amount', $v->errors()->toArray());
    }

    public function test_max_discount_amount_accepts_3_decimal(): void
    {
        $rules = ['max_discount_amount' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['max_discount_amount' => '50.123'], $rules);

        $this->assertEmpty($v->errors()->get('max_discount_amount'), 'Expected 50.123 to pass');
    }

    // ── minimum_order_amount (money scale 3) ─────────────────────────────────

    public function test_minimum_order_amount_rejects_4_decimal(): void
    {
        $rules = ['minimum_order_amount' => ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['minimum_order_amount' => '100.1234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 100.1234 to fail');
        $this->assertArrayHasKey('minimum_order_amount', $v->errors()->toArray());
    }

    public function test_minimum_order_amount_accepts_3_decimal(): void
    {
        $rules = ['minimum_order_amount' => ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['minimum_order_amount' => '100.123'], $rules);

        $this->assertEmpty($v->errors()->get('minimum_order_amount'), 'Expected 100.123 to pass');
    }

    // ── ValidateCoupon: items.*.quantity (changed integer→numeric, scale 4) ──

    public function test_item_quantity_rejects_5_decimal(): void
    {
        $rules = [
            'items' => ['sometimes', 'array'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
        $v = Validator::make(['items' => [['quantity' => '1.12345']]], $rules);

        $this->assertTrue($v->fails(), 'Expected 1.12345 to fail');
        $this->assertTrue($v->errors()->has('items.0.quantity'));
    }

    public function test_item_quantity_accepts_fractional(): void
    {
        $rules = [
            'items' => ['sometimes', 'array'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
        $v = Validator::make(['items' => [['quantity' => '1.5']]], $rules);

        $this->assertEmpty(
            $v->errors()->get('items.0.quantity'),
            'Expected 1.5 to pass'
        );
    }

    public function test_item_quantity_rejects_zero_was_previously_allowed(): void
    {
        // Old rule: integer, min:1 → could not be fractional.
        // New rule: numeric, min:0.0001 → zero still rejected.
        $rules = [
            'items' => ['sometimes', 'array'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
        $v = Validator::make(['items' => [['quantity' => '0']]], $rules);

        $this->assertTrue($v->fails(), 'Expected 0 to fail (min:0.0001)');
        $this->assertTrue($v->errors()->has('items.0.quantity'));
    }

    // ── ValidateCoupon: subtotal (money scale 3) ─────────────────────────────

    public function test_subtotal_rejects_4_decimal(): void
    {
        $rules = ['subtotal' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['subtotal' => '99.1234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 99.1234 to fail');
        $this->assertArrayHasKey('subtotal', $v->errors()->toArray());
    }

    public function test_subtotal_accepts_3_decimal(): void
    {
        $rules = ['subtotal' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['subtotal' => '99.123'], $rules);

        $this->assertEmpty($v->errors()->get('subtotal'), 'Expected 99.123 to pass');
    }
}
