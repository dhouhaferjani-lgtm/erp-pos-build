<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Presentation\Requests\CreateWithholdingCertificateRequest;
use App\Modules\Taxation\Presentation\Requests\CreateWithholdingRuleRequest;
use App\Modules\Taxation\Presentation\Requests\RecordSalesWithholdingRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.8 — Taxation / Withholding module ingress precision ceiling tests.
 *
 * Binds to the REAL production FormRequest rules (CreateWithholdingCertificate,
 * RecordSalesWithholding, CreateWithholdingRule) so these tests FAIL if a
 * production decimal scale changes. The two certificate/record requests resolve
 * CompanyContext::requireCompany() (DB lookup), so a tenant + company is seeded
 * and bound.
 */
final class WithholdingPrecisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a real CompanyContext (seeded tenant + company) in the container.
     */
    private function bindCompanyContext(): CompanyContext
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $context = app(CompanyContext::class);
        $context->setCompanyId($company->id);

        return $context;
    }

    /** @return array<string, mixed> */
    private function certificateRules(): array
    {
        return (new CreateWithholdingCertificateRequest($this->bindCompanyContext()))->rules();
    }

    /** @return array<string, mixed> */
    private function salesWithholdingRules(): array
    {
        return (new RecordSalesWithholdingRequest($this->bindCompanyContext()))->rules();
    }

    /** @return array<string, mixed> */
    private function ruleRules(): array
    {
        return (new CreateWithholdingRuleRequest)->rules();
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

    // ── 4.8.1 gross_amount (money scale 3) ───────────────────────────────────

    /** @dataProvider overPreciseMoneyProvider */
    public function test_gross_amount_rejects_over_precise(string $value): void
    {
        $this->assertFalse(
            $this->fieldPasses($this->certificateRules(), 'gross_amount', ['gross_amount' => $value]),
            "Expected gross_amount={$value} to fail"
        );
    }

    /** @dataProvider validMoneyProvider */
    public function test_gross_amount_accepts_valid(string $value): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->certificateRules(), 'gross_amount', ['gross_amount' => $value]),
            "Expected gross_amount={$value} to pass"
        );
    }

    // ── 4.8.2 manual_rate_percentage (percent scale 2, max 100) ─────────────

    public function test_manual_rate_percentage_rejects_3_decimal(): void
    {
        $this->assertFalse(
            $this->fieldPasses($this->certificateRules(), 'manual_rate_percentage', ['manual_rate_percentage' => '12.345']),
            'Expected 12.345 to fail (3 decimal places)'
        );
    }

    /** @dataProvider validPercentProvider */
    public function test_manual_rate_percentage_accepts_valid(string $value): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->certificateRules(), 'manual_rate_percentage', ['manual_rate_percentage' => $value]),
            "Expected manual_rate_percentage={$value} to pass"
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
        $this->assertFalse(
            $this->fieldPasses($this->salesWithholdingRules(), 'withholding_rate', ['withholding_rate' => '0.12345']),
            'Expected 0.12345 to fail (5 decimal places)'
        );
    }

    public function test_withholding_rate_accepts_4_decimal(): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->salesWithholdingRules(), 'withholding_rate', ['withholding_rate' => '0.1234']),
            'Expected 0.1234 to pass'
        );
    }

    // ── 4.8.4 invoice_amount / withholding_amount / expected_receivable (money/3) ──

    /** @dataProvider moneyFields3Provider */
    public function test_money_field_rejects_4_decimal(string $field, string $value): void
    {
        $this->assertFalse(
            $this->fieldPasses($this->salesWithholdingRules(), $field, [$field => $value]),
            "Expected {$field}={$value} to fail"
        );
    }

    /** @dataProvider moneyFields3Provider */
    public function test_money_field_accepts_3_decimal(string $field): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->salesWithholdingRules(), $field, [$field => '100.123']),
            "Expected {$field}=100.123 to pass"
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
        $this->assertFalse(
            $this->fieldPasses($this->ruleRules(), 'rate', ['rate' => '0.05123']),
            'Expected 0.05123 to fail'
        );
    }

    public function test_rule_rate_accepts_4_decimal(): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->ruleRules(), 'rate', ['rate' => '0.0512']),
            'Expected 0.0512 to pass'
        );
    }

    // ── 4.8.6 withholding_tax_rules.min_amount (money scale 3) ──────────────

    public function test_rule_min_amount_rejects_4_decimal(): void
    {
        $this->assertFalse(
            $this->fieldPasses($this->ruleRules(), 'min_amount', ['min_amount' => '1000.1234']),
            'Expected 1000.1234 to fail'
        );
    }

    public function test_rule_min_amount_accepts_3_decimal(): void
    {
        $this->assertTrue(
            $this->fieldPasses($this->ruleRules(), 'min_amount', ['min_amount' => '1000.123']),
            'Expected 1000.123 to pass'
        );
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
