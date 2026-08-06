<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Document\Presentation\Validation\DiscountPolicyDocumentValidator;
use App\Modules\Identity\Domain\User;
use App\Modules\Procurement\Application\PurchaseBonusGate;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.2 — Document module ingress precision ceiling tests.
 *
 * Document-line tests bind to the REAL production rules from
 * CreateDocumentRequest (CompanyContext bound) so they FAIL if a production
 * scale changes. The CreditNote tests mirror the inline validator in
 * CreditNoteController::store() (CreditNoteController.php:148/164/165 etc.) —
 * that endpoint requires a fully-built source invoice + customer to reach
 * validation, so a rules-literal is retained here with an explicit pointer to
 * the production callsite (it does NOT bind to production — see comments).
 */
final class IngressPrecisionTest extends TestCase
{
    use RefreshDatabase;
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
    //
    // NOTE: The CreditNote tests below use a rules-literal that MIRRORS (does
    // NOT bind to) the inline validator in CreditNoteController::store()
    // (app/Modules/Document/Presentation/Controllers/CreditNoteController.php
    // :148 amount, :164 unit_price, :165 tax_rate, :153/:163 quantity).
    // The endpoint needs a fully-built source invoice + customer to reach
    // validation, so a focused HTTP 422 test was deemed too costly here.

    public function test_credit_note_amount_rejects_4_decimal(): void
    {
        // Mirrors CreditNoteController.php:148 — NOT bound to production.
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
     * Production document-line rules from CreateDocumentRequest (CompanyContext
     * bound), re-keyed from `lines.*.<field>` to flat `<field>` so the flat
     * validLineData() payloads exercise them. Binds to production: if a
     * production line scale changes, these tests fail.
     *
     * @return array<string, mixed>
     */
    private function documentLineRules(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $context = app(CompanyContext::class);
        $context->setCompanyId($company->id);

        // CreateDocumentRequest::rules() reads $this->user()->tenant_id, so the
        // request needs a resolvable authenticated user.
        $request = new CreateDocumentRequest(
            $context,
            app(CompanyConfigService::class),
            app(PurchaseBonusGate::class),
            app(DiscountPolicyDocumentValidator::class),
            app(CurrencyScaleResolverInterface::class),
        );
        $request->setUserResolver(fn () => $user);

        $rules = $request->rules();

        $lineRules = [];
        foreach (['quantity', 'unit_price', 'discount_percent', 'discount_amount', 'tax_rate'] as $field) {
            $key = "lines.*.{$field}";
            $this->assertArrayHasKey(
                $key,
                $rules,
                "CreateDocumentRequest no longer exposes {$key} — the test no longer binds to production."
            );
            $lineRules[$field] = $rules[$key];
        }

        return $lineRules;
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
