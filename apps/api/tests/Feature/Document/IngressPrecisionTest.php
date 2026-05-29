<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.2 — Document module ingress precision ceiling tests.
 *
 * Proves that over-precise values are rejected (regex ceiling) and
 * valid values (at or within scale) are accepted.
 *
 * Uses Laravel's Validator::make() against the numeric/regex rules directly,
 * without needing the full FormRequest DI chain (CompanyContext, ScopedExists,
 * vehicle-module toggle, etc.).  The regex rules are the same as those in
 * CreateDocumentRequest / UpdateDocumentRequest / CreditNoteController.
 */
final class IngressPrecisionTest extends TestCase
{
    // ── Document line quantity (scale 4) ──────────────────────────────────────

    /**
     * @dataProvider overPreciseLineQuantityProvider
     */
    public function test_document_line_rejects_over_precise_quantity(string $qty): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['quantity' => $qty]), $rules);

        $this->assertTrue(
            $v->fails(),
            "Expected validation to fail for line quantity={$qty} but it passed"
        );
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overPreciseLineQuantityProvider(): array
    {
        return [
            '5-decimal quantity' => ['1.12345'],
            '6-decimal quantity' => ['2.000001'],
        ];
    }

    /**
     * @dataProvider validLineQuantityProvider
     */
    public function test_document_line_accepts_valid_quantity(string $qty): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['quantity' => $qty]), $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty(
            $errors,
            "Expected line quantity={$qty} to pass but got error: ".$v->errors()->first('quantity')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validLineQuantityProvider(): array
    {
        return [
            'integer' => ['1'],
            '2-decimal' => ['1.25'],
            '4-decimal' => ['1.1234'],
        ];
    }

    // ── Document line unit_price (scale 3) ────────────────────────────────────

    public function test_document_line_rejects_4_decimal_unit_price(): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['unit_price' => '9.9999']), $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('unit_price', $v->errors()->toArray());
    }

    public function test_document_line_accepts_3_decimal_unit_price(): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['unit_price' => '9.999']), $rules);

        $errors = $v->errors()->get('unit_price');
        $this->assertEmpty($errors, 'Expected 3-decimal unit_price to pass');
    }

    // ── Document line discount_percent (scale 2) ──────────────────────────────

    public function test_document_line_rejects_3_decimal_discount_percent(): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['discount_percent' => '10.123']), $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('discount_percent', $v->errors()->toArray());
    }

    public function test_document_line_accepts_2_decimal_discount_percent(): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['discount_percent' => '10.50']), $rules);

        $errors = $v->errors()->get('discount_percent');
        $this->assertEmpty($errors, 'Expected 2-decimal discount_percent to pass');
    }

    // ── Document line discount_amount (scale 3) ───────────────────────────────

    public function test_document_line_rejects_4_decimal_discount_amount(): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['discount_amount' => '1.2345']), $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('discount_amount', $v->errors()->toArray());
    }

    public function test_document_line_accepts_3_decimal_discount_amount(): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['discount_amount' => '1.234']), $rules);

        $errors = $v->errors()->get('discount_amount');
        $this->assertEmpty($errors, 'Expected 3-decimal discount_amount to pass');
    }

    // ── Document line tax_rate (scale 2) ──────────────────────────────────────

    public function test_document_line_rejects_3_decimal_tax_rate(): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['tax_rate' => '19.001']), $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tax_rate', $v->errors()->toArray());
    }

    public function test_document_line_accepts_2_decimal_tax_rate(): void
    {
        $rules = $this->documentLineRules();
        $v = Validator::make($this->validLineData(['tax_rate' => '19.00']), $rules);

        $errors = $v->errors()->get('tax_rate');
        $this->assertEmpty($errors, 'Expected 2-decimal tax_rate to pass');
    }

    // ── CreditNoteController: amount (scale 3) ────────────────────────────────

    public function test_credit_note_amount_rejects_4_decimal(): void
    {
        $rules = ['amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['amount' => '100.1234'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('amount', $v->errors()->toArray());
    }

    public function test_credit_note_amount_accepts_3_decimal(): void
    {
        $rules = ['amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['amount' => '100.123'], $rules);

        $errors = $v->errors()->get('amount');
        $this->assertEmpty($errors, 'Expected 3-decimal credit note amount to pass');
    }

    // ── CreditNoteController: standalone line unit_price (scale 3) ────────────

    public function test_credit_note_standalone_line_rejects_4_decimal_unit_price(): void
    {
        $rules = ['unit_price' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['unit_price' => '9.9999'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('unit_price', $v->errors()->toArray());
    }

    public function test_credit_note_standalone_line_accepts_3_decimal_unit_price(): void
    {
        $rules = ['unit_price' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['unit_price' => '9.999'], $rules);

        $errors = $v->errors()->get('unit_price');
        $this->assertEmpty($errors, 'Expected 3-decimal unit_price to pass');
    }

    // ── CreditNoteController: standalone line tax_rate (scale 2) ─────────────

    public function test_credit_note_standalone_line_rejects_3_decimal_tax_rate(): void
    {
        $rules = ['tax_rate' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/']];
        $v = Validator::make(['tax_rate' => '19.001'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tax_rate', $v->errors()->toArray());
    }

    // ── CreditNoteController: line-based quantity (scale 4) ───────────────────

    public function test_credit_note_line_based_rejects_5_decimal_quantity(): void
    {
        $rules = ['quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['quantity' => '2.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_credit_note_line_based_accepts_4_decimal_quantity(): void
    {
        $rules = ['quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/']];
        $v = Validator::make(['quantity' => '2.1234'], $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors, 'Expected 4-decimal quantity to pass');
    }

    // ── AppliesDiscountToleranceRule: discount_amount (scale 3) ──────────────

    public function test_tolerance_discount_amount_rejects_4_decimal(): void
    {
        // Mirrors the rule inserted by AppliesDiscountToleranceRule
        $rules = ['discount_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['discount_amount' => '5.1234'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('discount_amount', $v->errors()->toArray());
    }

    public function test_tolerance_discount_amount_accepts_3_decimal(): void
    {
        $rules = ['discount_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/']];
        $v = Validator::make(['discount_amount' => '5.123'], $rules);

        $errors = $v->errors()->get('discount_amount');
        $this->assertEmpty($errors, 'Expected 3-decimal discount_amount to pass');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Numeric/regex rules for a document line (mirrors CreateDocumentRequest &
     * UpdateDocumentRequest — ScopedExists and required_with are omitted since
     * those need DB/DI).
     *
     * @return array<string, mixed>
     */
    private function documentLineRules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * A valid minimal document line for use with overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validLineData(array $overrides = []): array
    {
        return array_merge([
            'quantity' => '1',
            'unit_price' => '10.000',
        ], $overrides);
    }
}
