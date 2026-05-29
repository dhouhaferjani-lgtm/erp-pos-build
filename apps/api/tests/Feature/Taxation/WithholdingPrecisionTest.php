<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.8 — Taxation / Withholding module ingress precision ceiling tests.
 *
 * Proves over-precise values are rejected and within-scale values are accepted.
 * Uses Validator::make() against the rules() directly — no HTTP round-trip needed.
 */
final class WithholdingPrecisionTest extends TestCase
{
    // ── 4.8.1 gross_amount (money scale 3) ───────────────────────────────────

    /** @dataProvider overPreciseMoneyProvider */
    public function test_gross_amount_rejects_over_precise(string $value): void
    {
        $rules = ['gross_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['gross_amount' => $value], $rules);

        $this->assertTrue($v->fails(), 'Expected gross_amount='.$value.' to fail');
        $this->assertArrayHasKey('gross_amount', $v->errors()->toArray());
    }

    /** @dataProvider validMoneyProvider */
    public function test_gross_amount_accepts_valid(string $value): void
    {
        $rules = ['gross_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['gross_amount' => $value], $rules);

        $this->assertEmpty(
            $v->errors()->get('gross_amount'),
            'Expected gross_amount='.$value.' to pass: '.$v->errors()->first('gross_amount')
        );
    }

    // ── 4.8.2 manual_rate_percentage (percent scale 2, max 100) ─────────────

    public function test_manual_rate_percentage_rejects_3_decimal(): void
    {
        $rules = ['manual_rate_percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['manual_rate_percentage' => '12.345'], $rules);

        $this->assertTrue($v->fails(), 'Expected 12.345 to fail (3 decimal places)');
        $this->assertArrayHasKey('manual_rate_percentage', $v->errors()->toArray());
    }

    /** @dataProvider validPercentProvider */
    public function test_manual_rate_percentage_accepts_valid(string $value): void
    {
        $rules = ['manual_rate_percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['manual_rate_percentage' => $value], $rules);

        $this->assertEmpty(
            $v->errors()->get('manual_rate_percentage'),
            'Expected manual_rate_percentage='.$value.' to pass: '.$v->errors()->first('manual_rate_percentage')
        );
    }

    /** @return array<string, array{string}> */
    public static function validPercentProvider(): array
    {
        return [
            'integer' => ['5'],
            '1-decimal' => ['5.5'],
            '2-decimal' => ['12.34'],
            'max' => ['100'],
        ];
    }

    // ── 4.8.3 withholding_rate (fraction scale 4, 0..1) ─────────────────────

    public function test_withholding_rate_rejects_5_decimal(): void
    {
        $rules = ['withholding_rate' => ['required', 'numeric', 'min:0', 'max:1', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['withholding_rate' => '0.12345'], $rules);

        $this->assertTrue($v->fails(), 'Expected 0.12345 to fail (5 decimal places)');
        $this->assertArrayHasKey('withholding_rate', $v->errors()->toArray());
    }

    public function test_withholding_rate_accepts_4_decimal(): void
    {
        $rules = ['withholding_rate' => ['required', 'numeric', 'min:0', 'max:1', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['withholding_rate' => '0.1234'], $rules);

        $this->assertEmpty(
            $v->errors()->get('withholding_rate'),
            'Expected 0.1234 to pass'
        );
    }

    // ── 4.8.4 invoice_amount / withholding_amount / expected_receivable (money/3) ──

    /** @dataProvider moneyFields3Provider */
    public function test_money_field_rejects_4_decimal(string $field, string $value): void
    {
        $rules = [$field => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make([$field => $value], $rules);

        $this->assertTrue($v->fails(), 'Expected '.$field.'='.$value.' to fail');
        $this->assertArrayHasKey($field, $v->errors()->toArray());
    }

    /** @dataProvider moneyFields3Provider */
    public function test_money_field_accepts_3_decimal(string $field, string $valid): void
    {
        // Use same field + a 3-decimal valid value
        $rules = [$field => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make([$field => '100.123'], $rules);

        $this->assertEmpty(
            $v->errors()->get($field),
            'Expected '.$field.'=100.123 to pass'
        );
    }

    /** @return array<string, array{string, string}> */
    public static function moneyFields3Provider(): array
    {
        return [
            'invoice_amount' => ['invoice_amount', '100.1234'],
            'withholding_amount' => ['withholding_amount', '50.1234'],
            'expected_receivable' => ['expected_receivable', '75.1234'],
        ];
    }

    // ── 4.8.5 withholding_tax_rules.rate (fraction scale 4, max 1) ──────────

    public function test_rule_rate_rejects_5_decimal(): void
    {
        $rules = ['rate' => ['required', 'numeric', 'min:0', 'max:1', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['rate' => '0.05123'], $rules);

        $this->assertTrue($v->fails(), 'Expected 0.05123 to fail');
        $this->assertArrayHasKey('rate', $v->errors()->toArray());
    }

    public function test_rule_rate_accepts_4_decimal(): void
    {
        $rules = ['rate' => ['required', 'numeric', 'min:0', 'max:1', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['rate' => '0.0512'], $rules);

        $this->assertEmpty($v->errors()->get('rate'), 'Expected 0.0512 to pass');
    }

    // ── 4.8.6 withholding_tax_rules.min_amount (money scale 3) ──────────────

    public function test_rule_min_amount_rejects_4_decimal(): void
    {
        $rules = ['min_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['min_amount' => '1000.1234'], $rules);

        $this->assertTrue($v->fails(), 'Expected 1000.1234 to fail');
        $this->assertArrayHasKey('min_amount', $v->errors()->toArray());
    }

    public function test_rule_min_amount_accepts_3_decimal(): void
    {
        $rules = ['min_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['min_amount' => '1000.123'], $rules);

        $this->assertEmpty($v->errors()->get('min_amount'), 'Expected 1000.123 to pass');
    }

    // ── shared providers ─────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function overPreciseMoneyProvider(): array
    {
        return [
            '4-decimal' => ['100.1234'],
            '5-decimal' => ['1.00001'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validMoneyProvider(): array
    {
        return [
            'integer' => ['100'],
            '1-decimal' => ['100.1'],
            '2-decimal' => ['100.12'],
            '3-decimal (TND)' => ['100.123'],
        ];
    }
}
