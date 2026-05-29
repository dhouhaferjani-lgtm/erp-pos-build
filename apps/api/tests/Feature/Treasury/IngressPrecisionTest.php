<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.4 — Treasury module ingress precision ceiling tests.
 *
 * Proves that over-precise values are rejected (regex ceiling) and that valid
 * values (at or within scale) are accepted. Mirrors the established pattern in
 * tests/Feature/Document/IngressPrecisionTest.php: Validator::make() against the
 * numeric/regex rules directly, without the full FormRequest DI chain.
 *
 * The rules below are the same as those in PaymentController, MultiPaymentController,
 * PaymentInstrumentController, PaymentRefundController, PaymentMethodController,
 * BankReconciliationController and RefundPrepaymentRequest.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── Payment amount (money, scale 3) ───────────────────────────────────────

    /**
     * @dataProvider overPreciseMoneyProvider
     */
    public function test_payment_amount_rejects_over_precise(string $amount): void
    {
        $rules = ['amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['amount' => $amount], $rules);

        $this->assertTrue($v->fails(), "Expected amount={$amount} to fail");
        $this->assertArrayHasKey('amount', $v->errors()->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overPreciseMoneyProvider(): array
    {
        return [
            '4-decimal' => ['100.1234'],
            '5-decimal' => ['1.00001'],
        ];
    }

    /**
     * @dataProvider validMoneyProvider
     */
    public function test_payment_amount_accepts_valid(string $amount): void
    {
        $rules = ['amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['amount' => $amount], $rules);

        $this->assertEmpty(
            $v->errors()->get('amount'),
            "Expected amount={$amount} to pass: ".$v->errors()->first('amount')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validMoneyProvider(): array
    {
        return [
            'integer' => ['100'],
            '2-decimal' => ['100.12'],
            '3-decimal (TND)' => ['100.123'],
        ];
    }

    // ── Withholding rate (rate, scale 2) ──────────────────────────────────────

    public function test_withholding_rate_rejects_3_decimal(): void
    {
        $rules = ['withholding_rate' => ['nullable', 'numeric', 'min:0', 'max:1', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['withholding_rate' => '0.155'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('withholding_rate', $v->errors()->toArray());
    }

    public function test_withholding_rate_accepts_2_decimal(): void
    {
        $rules = ['withholding_rate' => ['nullable', 'numeric', 'min:0', 'max:1', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['withholding_rate' => '0.15'], $rules);

        $this->assertEmpty($v->errors()->get('withholding_rate'));
    }

    // ── Bank reconciliation statement_balance (signed money, scale 3) ─────────

    public function test_statement_balance_rejects_4_decimal(): void
    {
        $rules = ['statement_balance' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['statement_balance' => '1000.1234'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('statement_balance', $v->errors()->toArray());
    }

    public function test_statement_balance_accepts_negative_3_decimal(): void
    {
        $rules = ['statement_balance' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['statement_balance' => '-250.500'], $rules);

        $this->assertEmpty(
            $v->errors()->get('statement_balance'),
            'Expected negative (overdrawn) 3-decimal balance to pass'
        );
    }

    // ── PaymentMethod fee_fixed (money/3) + fee_percent (percent/2) ───────────

    public function test_fee_fixed_rejects_4_decimal(): void
    {
        $rules = ['fee_fixed' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['fee_fixed' => '2.5001'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('fee_fixed', $v->errors()->toArray());
    }

    public function test_fee_percent_rejects_3_decimal(): void
    {
        $rules = ['fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['fee_percent' => '2.555'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('fee_percent', $v->errors()->toArray());
    }

    public function test_fee_percent_accepts_2_decimal(): void
    {
        $rules = ['fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['fee_percent' => '2.55'], $rules);

        $this->assertEmpty($v->errors()->get('fee_percent'));
    }
}
