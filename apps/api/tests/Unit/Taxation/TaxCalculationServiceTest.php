<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

class TaxCalculationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private TaxCalculationService $service;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        // TND scale 3 — matches the TN company under test and surfaces
        // scale-2 regressions.
        $this->service = new TaxCalculationService($this->mockCurrencyScale());
        $this->seed(CountriesSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['country_code' => 'TN']);
    }

    /** @test */
    public function it_calculates_single_percentage_tax(): void
    {
        // Setup: Create company, partner, tax config
        $company = $this->company;
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $taxConfig = TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '19.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Test item',
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);

        // Reload with lines
        $document->load('lines');

        // Execute
        $result = $this->service->calculateDocumentTaxes($document);

        // Assert
        $this->assertCount(1, $result->taxes);
        $this->assertEquals('100.000', $result->subtotal);
        $this->assertEquals('19.000', $result->lineItemsTaxTotal);
        $this->assertEquals('0', $result->documentTaxTotal);
        $this->assertEquals('19.000', $result->totalTax);
        $this->assertEquals('119.000', $result->total);
    }

    /** @test */
    public function it_calculates_fixed_amount_tax(): void
    {
        // Setup
        $company = $this->company;
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $stampDuty = TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'Stamp Duty',
            'code' => 'STAMP_1',
            'tax_type' => 'FIXED_AMOUNT',
            'fixed_amount' => '1.000',
            'applies_to' => 'DOCUMENT_TOTAL',
            'sequence_order' => 99,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => true,
        ]);

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Test item',
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '0',
            'line_total' => '100.000',
        ]);

        $document->load('lines');

        // Execute
        $result = $this->service->calculateDocumentTaxes($document);

        // Assert
        $this->assertCount(1, $result->taxes);
        $this->assertEquals('100.000', $result->subtotal);
        $this->assertEquals('0', $result->lineItemsTaxTotal);
        $this->assertEquals('1.000', $result->documentTaxTotal);
        $this->assertEquals('1.000', $result->totalTax);
        $this->assertEquals('101.000', $result->total);
        $this->assertTrue($result->taxes[0]->isStampDuty);
    }

    /**
     * DEFECT 1 (2026-08-02 gate F4 / 2026-08-02-documents-gate-followups.md):
     * TaxCalculationService::calculateDocumentTaxes() used to snapshot
     * base: $subtotal (the WHOLE document subtotal) on EVERY rate row. A
     * 3-rate document therefore snapshotted Σ tax_base = 3× subtotal, and
     * the VAT declaration's SUM(dtd.tax_base) inflated the declared base
     * per extra rate. Each LINE_ITEMS rate row's tax_base must be the base
     * ACTUALLY taxed at that rate (the per-rate bucket net) so that, on a
     * document where every line carries a taxed (non-zero) rate, the rows
     * sum EXACTLY to the document subtotal — not a multiple of it.
     *
     * @test
     */
    public function it_snapshots_per_rate_tax_base_as_the_taxed_bucket_not_the_whole_subtotal(): void
    {
        $company = $this->company;
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '19.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);

        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 7%',
            'code' => 'TVA_7',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '7.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 2,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        // Two lines at 19% totalling 100.000, one line at 7% totalling
        // 200.000. Every line carries a taxed rate, so subtotal (300.000)
        // must equal the sum of the two per-rate tax_base rows exactly.
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Item A',
            'quantity' => '1',
            'unit_price' => '60.000',
            'tax_rate' => '19.00',
            'line_total' => '60.000',
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 2,
            'description' => 'Item B',
            'quantity' => '1',
            'unit_price' => '40.000',
            'tax_rate' => '19.00',
            'line_total' => '40.000',
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 3,
            'description' => 'Item C',
            'quantity' => '1',
            'unit_price' => '200.000',
            'tax_rate' => '7.00',
            'line_total' => '200.000',
        ]);

        $document->load('lines');

        $result = $this->service->calculateDocumentTaxes($document);

        $this->assertEquals('300.000', $result->subtotal);
        $this->assertCount(2, $result->taxes);

        $byRate = [];
        foreach ($result->taxes as $tax) {
            $byRate[$tax->rate] = $tax;
        }

        // The 19% bucket is ONLY the two 19% lines (60 + 40 = 100.000) —
        // NOT the whole 300.000 document subtotal.
        $this->assertSame('100.000', $byRate['19.00']->base);
        $this->assertSame('19.000', $byRate['19.00']->amount);

        // The 7% bucket is ONLY the one 7% line (200.000) — NOT the whole
        // document subtotal either.
        $this->assertSame('200.000', $byRate['7.00']->base);
        $this->assertSame('14.000', $byRate['7.00']->amount);

        // Every line in this document carries a taxed rate, so the two
        // rate-bucket bases must sum EXACTLY to the document subtotal —
        // the bug summed to 2× subtotal (600.000) instead.
        $sumOfBases = bcadd($byRate['19.00']->base, $byRate['7.00']->base, 3);
        $this->assertSame($result->subtotal, $sumOfBases);
    }

    /**
     * Same defect, unconfigured-rate branch (TaxCalculationService.php:182
     * area pre-fix): an unconfigured rate must ALSO snapshot its own bucket
     * net, not the whole subtotal.
     *
     * @test
     */
    public function it_snapshots_per_rate_tax_base_for_unconfigured_rates_too(): void
    {
        $company = $this->company;
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        // Only 19% is configured; 21% has no TaxConfiguration row at all.
        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '19.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Item A (configured 19%)',
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 2,
            'description' => 'Item B (unconfigured 21%)',
            'quantity' => '1',
            'unit_price' => '200.000',
            'tax_rate' => '21.00',
            'line_total' => '200.000',
        ]);

        $document->load('lines');

        $result = $this->service->calculateDocumentTaxes($document);

        $this->assertEquals('300.000', $result->subtotal);
        $this->assertCount(2, $result->taxes);

        $byRate = [];
        foreach ($result->taxes as $tax) {
            $byRate[$tax->rate] = $tax;
        }

        $this->assertSame('100.000', $byRate['19.00']->base);
        $this->assertSame('UNCONFIGURED', $byRate['21.00']->code);
        $this->assertSame('200.000', $byRate['21.00']->base);

        $sumOfBases = bcadd($byRate['19.00']->base, $byRate['21.00']->base, 3);
        $this->assertSame($result->subtotal, $sumOfBases);
    }

    /** @test */
    public function it_returns_exemption_info_for_exempt_partner(): void
    {
        // Setup
        $company = $this->company;
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::EXEMPT,
            'tax_exemption_reason' => 'Government entity',
        ]);

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Test item',
            'quantity' => '1',
            'unit_price' => '100.000',
            'line_total' => '100.000',
        ]);

        $document->load('lines');

        // Execute
        $result = $this->service->calculateDocumentTaxes($document);

        // Assert
        $this->assertNotNull($result->exemptionInfo);
        $this->assertEquals('EXEMPT', $result->exemptionInfo['status']);
        $this->assertEquals('Government entity', $result->exemptionInfo['reason']);
        $this->assertFalse($result->exemptionInfo['hasValidCertificate']);
        $this->assertNotEmpty($result->exemptionInfo['warnings']);
    }
}
