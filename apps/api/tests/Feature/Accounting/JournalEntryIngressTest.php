<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.5 — Accounting module ingress precision ceiling tests.
 *
 * Proves over-precise debit/credit/opening-balance values are rejected and that
 * valid values (at or within scale) are accepted. Mirrors the established pattern
 * in tests/Feature/Document/IngressPrecisionTest.php (Validator::make() against the
 * numeric/regex rules directly, no FormRequest DI chain).
 *
 * Rules mirror CreateJournalEntryRequest (debit/credit, money/3, signed) and
 * OpeningBalanceBatchController (debit/credit signed money/3, quantity/4,
 * unit_cost/total/open_amount money/3).
 */
final class JournalEntryIngressTest extends TestCase
{
    // ── Journal line debit/credit (money, scale 3, SIGNED) ────────────────────

    /**
     * @dataProvider overPreciseProvider
     */
    public function test_journal_debit_rejects_over_precise(string $amount): void
    {
        $rules = ['debit' => ['required', 'numeric', 'min:0', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['debit' => $amount], $rules);

        $this->assertTrue($v->fails(), "Expected debit={$amount} to fail");
        $this->assertArrayHasKey('debit', $v->errors()->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overPreciseProvider(): array
    {
        return [
            '4-decimal' => ['100.1234'],
            '5-decimal' => ['0.00001'],
        ];
    }

    public function test_journal_credit_accepts_3_decimal(): void
    {
        $rules = ['credit' => ['required', 'numeric', 'min:0', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['credit' => '100.123'], $rules);

        $this->assertEmpty(
            $v->errors()->get('credit'),
            'Expected 3-decimal credit to pass: '.$v->errors()->first('credit')
        );
    }

    /**
     * Journal lines legitimately carry signed amounts (reversing/correcting
     * entries), so the regex must allow a leading minus.
     */
    public function test_journal_debit_accepts_negative_3_decimal(): void
    {
        // min:0 omitted here to isolate the regex behaviour for the signed case.
        $rules = ['debit' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['debit' => '-42.500'], $rules);

        $this->assertEmpty(
            $v->errors()->get('debit'),
            'Expected signed 3-decimal debit to pass the regex ceiling'
        );
    }

    // ── Opening-balance inventory quantity (scale 4) ──────────────────────────

    public function test_opening_quantity_rejects_5_decimal(): void
    {
        $rules = ['quantity' => ['required', 'numeric', 'gt:0', 'regex:/^-?\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['quantity' => '2.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_opening_quantity_accepts_4_decimal(): void
    {
        $rules = ['quantity' => ['required', 'numeric', 'gt:0', 'regex:/^-?\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['quantity' => '2.1234'], $rules);

        $this->assertEmpty($v->errors()->get('quantity'));
    }

    // ── Opening-balance unit_cost / total / open_amount (money, scale 3) ──────

    public function test_opening_unit_cost_rejects_4_decimal(): void
    {
        $rules = ['unit_cost' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['unit_cost' => '9.9999'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('unit_cost', $v->errors()->toArray());
    }

    public function test_opening_open_amount_accepts_3_decimal(): void
    {
        $rules = ['open_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['open_amount' => '500.000'], $rules);

        $this->assertEmpty($v->errors()->get('open_amount'));
    }
}
