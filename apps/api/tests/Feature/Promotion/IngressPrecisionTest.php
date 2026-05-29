<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.10 — Promotion module ingress precision ceiling tests.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── discount_value (decimal 12,4) ─────────────────────────────────────────

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
            '5-decimal' => ['15.12345'],
            '6-decimal' => ['15.000001'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validDiscountValueProvider(): array
    {
        return [
            'integer' => ['15'],
            '2-decimal' => ['15.25'],
            '4-decimal' => ['15.1234'],
        ];
    }

    // ── max_discount_amount (money scale 3) ──────────────────────────────────

    public function test_max_discount_amount_rejects_4_decimal(): void
    {
        $rules = ['max_discount_amount' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['max_discount_amount' => '200.1234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 200.1234 to fail');
        $this->assertArrayHasKey('max_discount_amount', $v->errors()->toArray());
    }

    public function test_max_discount_amount_accepts_3_decimal(): void
    {
        $rules = ['max_discount_amount' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['max_discount_amount' => '200.123'], $rules);

        $this->assertEmpty($v->errors()->get('max_discount_amount'), 'Expected 200.123 to pass');
    }

    // ── conditions.min_amount (JSONB money scale 3) ──────────────────────────

    public function test_conditions_min_amount_rejects_4_decimal(): void
    {
        $rules = ['conditions.min_amount' => ['sometimes', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['conditions' => ['min_amount' => '50.1234']], $rules);

        $this->assertTrue($v->fails(), 'Expected 50.1234 to fail');
        $this->assertTrue($v->errors()->has('conditions.min_amount'));
    }

    public function test_conditions_min_amount_accepts_3_decimal(): void
    {
        $rules = ['conditions.min_amount' => ['sometimes', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['conditions' => ['min_amount' => '50.123']], $rules);

        $this->assertEmpty($v->errors()->get('conditions.min_amount'), 'Expected 50.123 to pass');
    }
}
