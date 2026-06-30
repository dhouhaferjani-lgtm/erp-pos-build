<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Domain\DTOs\VatDeclarationData;
use App\Modules\Taxation\Domain\DTOs\VatSummary;
use App\Modules\Taxation\Infrastructure\Exporters\MtdJsonExporter;
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

    // ── 4.8.7 MTD JSON exporter — bcmath throughout, float only at JSON edge ──

    /**
     * P0-6: box5 (netVatDue) must be the absolute value of (box3 − box4) computed
     * entirely in bcmath. The final JSON must carry a numeric type (not a string).
     *
     * Scenario: box3 = 500.50, box4 = 600.75 → netVat = -100.25 → box5 = 100.25.
     * abs() must be done via bcmath (ltrim/bccomp), not abs((float)…).
     */
    public function test_mtd_box5_is_bcmath_abs_of_box3_minus_box4(): void
    {
        $exporter = app(MtdJsonExporter::class);

        // Supply box1–4 explicitly so box3-box4 arithmetic is predictable.
        $summary = new VatSummary([], [], '0.00', '0.00');
        $declaration = new VatDeclarationData([
            'box1_vat_due_sales' => '500.50',
            'box2_vat_due_acquisitions' => '0.00',
            'box3_total_vat_due' => '500.50',
            'box4_vat_reclaimed' => '600.75',
        ], 'VAT100');

        $json = $this->captureExportJson($exporter, $summary, $declaration);

        // box5 = abs(500.50 − 600.75) = abs(-100.25) = 100.25
        $this->assertSame(100.25, $json['netVatDue'],
            'box5 must equal |box3 − box4| computed in bcmath (100.25)');

        // HMRC MTD contract: box values must be numeric (float|int), never JSON strings.
        $this->assertIsFloat($json['netVatDue'], 'HMRC MTD requires numeric JSON type for box5');
        $this->assertIsFloat($json['vatDueSales'], 'HMRC MTD requires numeric JSON type for box1');
        $this->assertIsFloat($json['vatReclaimedCurrPeriod'], 'HMRC MTD requires numeric JSON type for box4');
    }

    /**
     * P0-6: when netVat is already positive, box5 stays positive (no sign flip).
     *
     * Scenario: box3 = 700.00, box4 = 300.00 → netVat = +400.00 → box5 = 400.00.
     */
    public function test_mtd_box5_positive_when_net_vat_is_positive(): void
    {
        $exporter = app(MtdJsonExporter::class);

        $summary = new VatSummary([], [], '0.00', '0.00');
        $declaration = new VatDeclarationData([
            'box1_vat_due_sales' => '700.00',
            'box2_vat_due_acquisitions' => '0.00',
            'box3_total_vat_due' => '700.00',
            'box4_vat_reclaimed' => '300.00',
        ], 'VAT100');

        $json = $this->captureExportJson($exporter, $summary, $declaration);

        // netVat = 700.00 - 300.00 = +400.00 (positive) → box5 = 400.00 (already positive)
        $this->assertSame(400.0, $json['netVatDue'],
            'box5 must stay positive when netVat is already positive');
        $this->assertIsFloat($json['netVatDue']);
    }

    /**
     * P0-6: MTD boxes 6-9 whole-pound floor must be computed in bcmath, not via
     * floor((float) $value).
     *
     * Positive fractional case: '1234.99' → floor = 1234 (truncation toward zero = floor).
     * Negative fractional case: '-5678.01' → floor = -5679 (toward −∞, NOT toward zero).
     *
     * The bcmath path: bcadd('-5678.01','0',0) = '-5678'; since -5678.01 < -5678 (bccomp
     * < 0), bcsub gives '-5679'. A naïve (int) truncation toward zero would give -5678,
     * proving the floor semantic is enforced by the bcsub branch.
     *
     * Both boxes must be PHP int in the JSON payload (HMRC MTD contract).
     */
    public function test_mtd_boxes_6_7_whole_pound_floor_via_bcmath(): void
    {
        $exporter = app(MtdJsonExporter::class);

        $summary = new VatSummary([], [], '0.00', '0.00');
        $declaration = new VatDeclarationData([
            'box1_vat_due_sales' => '0.00',
            'box2_vat_due_acquisitions' => '0.00',
            'box3_total_vat_due' => '0.00',
            'box4_vat_reclaimed' => '0.00',
            'box6_total_sales_ex_vat' => '1234.99',
            'box7_total_purchases_ex_vat' => '-5678.01',
        ], 'VAT100');

        $json = $this->captureExportJson($exporter, $summary, $declaration);

        // Positive fractional: floor(1234.99) = 1234
        $this->assertSame(1234, $json['totalValueSalesExVAT'],
            'box6: bcmath floor of 1234.99 must yield integer 1234');

        // Negative fractional: floor(-5678.01) = -5679 (toward −∞, not toward zero)
        // A (int) truncation would incorrectly give -5678; the bcmath bcsub branch gives -5679.
        $this->assertSame(-5679, $json['totalValuePurchasesExVAT'],
            'box7: bcmath floor of -5678.01 must yield -5679 (toward −∞, not toward zero)');

        // Both must be PHP int in the HMRC MTD payload — never float
        $this->assertIsInt($json['totalValueSalesExVAT'],
            'box6 must carry PHP int type in the HMRC MTD JSON payload');
        $this->assertIsInt($json['totalValuePurchasesExVAT'],
            'box7 must carry PHP int type in the HMRC MTD JSON payload');
    }

    /**
     * P0-6: zero netVat yields box5 = 0.0 (numeric JSON, never a string zero).
     */
    public function test_mtd_box5_is_zero_when_box3_equals_box4(): void
    {
        $exporter = app(MtdJsonExporter::class);

        $summary = new VatSummary([], [], '0.00', '0.00');
        $declaration = new VatDeclarationData([
            'box1_vat_due_sales' => '250.00',
            'box2_vat_due_acquisitions' => '0.00',
            'box3_total_vat_due' => '250.00',
            'box4_vat_reclaimed' => '250.00',
        ], 'VAT100');

        $json = $this->captureExportJson($exporter, $summary, $declaration);

        $this->assertSame(0.0, $json['netVatDue'],
            'box5 must be 0.0 when box3 equals box4');
        $this->assertIsFloat($json['netVatDue']);
    }

    /**
     * Helper: invoke the exporter and return the decoded JSON payload.
     *
     * @return array<string, float|int>
     */
    private function captureExportJson(
        MtdJsonExporter $exporter,
        VatSummary $summary,
        VatDeclarationData $declaration,
    ): array {
        $response = $exporter->export($summary, $declaration);

        ob_start();
        $response->sendContent();
        $raw = ob_get_clean();

        /** @var array<string, float|int> $decoded */
        $decoded = json_decode((string) $raw, true);

        return $decoded;
    }
}
