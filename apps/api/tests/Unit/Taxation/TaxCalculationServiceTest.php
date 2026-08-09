<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\FiscalHashService;
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

        // Assert. V3 (2026-08-03 gate): an explicit 0% line now snapshots a
        // base-only row (so the DGI base_0 bracket is populated) alongside
        // the stamp — 2 rows, not 1. STEP 1 (line items) runs before STEP 2
        // (document total), so taxes[0] is the 0% row and taxes[1] is stamp.
        $this->assertCount(2, $result->taxes);
        $this->assertEquals('100.000', $result->subtotal);
        // '0.000', not '0': the 0% line now flows through the accumulator
        // (V3) instead of being skipped, so it is bcadd-formatted at scale.
        $this->assertEquals('0.000', $result->lineItemsTaxTotal);
        $this->assertEquals('1.000', $result->documentTaxTotal);
        $this->assertEquals('1.000', $result->totalTax);
        $this->assertEquals('101.000', $result->total);

        $zeroRateTax = $result->taxes[0];
        $this->assertSame('0.00', $zeroRateTax->rate);
        $this->assertSame('100.000', $zeroRateTax->base);
        $this->assertSame('0.000', $zeroRateTax->amount);
        $this->assertFalse($zeroRateTax->isStampDuty);

        $stampTax = $result->taxes[1];
        $this->assertTrue($stampTax->isStampDuty);
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

    /**
     * V1 (2026-08-03 gate, P0 REGRESSION in 74383eb19): a document-level
     * discount was not reflected in the per-rate tax_base at all — the
     * bucket base stayed at the pre-discount line net, so a single-rate
     * document's declared base OVERSTATED the true discounted base by the
     * full discount, and VAT was charged on the pre-discount amount.
     *
     * Gate's exact probe: single line 300.000 @ 19%, discount_amount
     * 50.000 → legally-correct base = 250.000, legally-correct VAT = 47.500.
     *
     * @test
     */
    public function it_reduces_the_taxed_base_by_a_document_level_discount(): void
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

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'discount_amount' => '50.000',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Item A',
            'quantity' => '1',
            'unit_price' => '300.000',
            'tax_rate' => '19.00',
            'line_total' => '300.000',
        ]);

        $document->load('lines');

        $result = $this->service->calculateDocumentTaxes($document);

        $this->assertSame('250.000', $result->subtotal);
        $this->assertCount(1, $result->taxes);
        $this->assertSame('250.000', $result->taxes[0]->base, 'Gate probe B: declared base must be the discounted 250.000, not the pre-discount 300.000');
        $this->assertSame('47.500', $result->taxes[0]->amount, 'Gate probe B: VAT is charged on the discounted base');
        $this->assertSame('47.500', $result->lineItemsTaxTotal);
        $this->assertSame('297.500', $result->total);
    }

    /**
     * V1, multi-rate case (gate probe A2): two rate buckets share a single
     * document-level discount, prorated by each bucket's pre-discount base
     * share. 19% bucket 100.000 + 7% bucket 200.000 = 300.000 pre-discount;
     * discount 50.000 splits 1/3 : 2/3 -> 16.667 : 33.333 (largest-remainder
     * makes the two shares sum to EXACTLY 50.000). Post-discount bases must
     * sum EXACTLY to the discounted subtotal (250.000) — the gate's core
     * complaint was that this invariant broke under a discount.
     *
     * @test
     */
    public function it_prorates_a_document_level_discount_across_multiple_rate_buckets(): void
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
            'discount_amount' => '50.000',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Item A',
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 2,
            'description' => 'Item B',
            'quantity' => '1',
            'unit_price' => '200.000',
            'tax_rate' => '7.00',
            'line_total' => '200.000',
        ]);

        $document->load('lines');

        $result = $this->service->calculateDocumentTaxes($document);

        $this->assertSame('250.000', $result->subtotal);
        $this->assertCount(2, $result->taxes);

        $byRate = [];
        foreach ($result->taxes as $tax) {
            $byRate[$tax->rate] = $tax;
        }

        // Pre-discount bases were 100.000 (19%) and 200.000 (7%), a 1:2
        // split of the 300.000 pre-discount total. The 50.000 discount
        // prorates 16.667 : 33.333 (largest-remainder gives the 19% bucket
        // the leftover smallest unit: raw shares 16.6666666 / 33.3333333
        // both floor with a residual, and 19%'s remainder is larger).
        $this->assertSame('83.333', $byRate['19.00']->base);
        $this->assertSame('15.833', $byRate['19.00']->amount);
        $this->assertSame('166.667', $byRate['7.00']->base);
        $this->assertSame('11.666', $byRate['7.00']->amount);

        // Discount shares must sum to EXACTLY 50.000 (largest-remainder):
        // post-discount bucket bases sum EXACTLY to the discounted subtotal.
        $sumOfBases = bcadd($byRate['19.00']->base, $byRate['7.00']->base, 3);
        $this->assertSame('250.000', $sumOfBases, 'Post-discount bucket bases must sum EXACTLY to the discounted subtotal');

        // Each bucket's own base × rate == amount identity holds exactly.
        $this->assertSame(
            bcmul($byRate['19.00']->base, '0.19', 3),
            $byRate['19.00']->amount,
        );
        $this->assertSame(
            bcmul($byRate['7.00']->base, '0.07', 3),
            $byRate['7.00']->amount,
        );
    }

    /**
     * V4 (2026-08-03 gate, P1): the 74383eb19 fix's own comment claimed
     * Σ(rateBase) == $subtotal "exactly" but the base was accumulated at
     * scale+1 and rounded ONCE per bucket, while calculateSubtotal()
     * truncates PER LINE then sums — a different rounding order that drifts
     * under sub-scale truncation. Gate's exact probe A3: 3 lines of
     * qty 1.5000 × 0.333 = 0.4995 each, all at 19%, no discounts.
     *
     * @test
     */
    public function it_ties_the_declared_base_to_the_subtotal_exactly_under_sub_scale_truncation(): void
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

        $document = Document::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            DocumentLine::create([
                'document_id' => $document->id,
                'line_number' => $i,
                'description' => 'Frac line '.$i,
                'quantity' => '1.5000',
                'unit_price' => '0.333',
                'tax_rate' => '19.00',
                'line_total' => '0.4995',
            ]);
        }

        $document->load('lines');

        $result = $this->service->calculateDocumentTaxes($document);

        // subtotal = 3 × truncate(1.5000 × 0.333, 3) = 3 × 0.499 = 1.497.
        $this->assertSame('1.497', $result->subtotal);
        $this->assertCount(1, $result->taxes);
        // Gate probe: pre-fix delta was +0.001 (base=1.498 vs subtotal
        // 1.497). Post-fix the declared base ties to the subtotal exactly.
        $this->assertSame('1.497', $result->taxes[0]->base);
    }

    public function test_non_stamp_document_total_rows_cannot_change_tn_stamp_totals_or_hash_input_bytes(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'Timbre fiscal',
            'code' => 'STAMP_BYTES',
            'tax_type' => 'FIXED_AMOUNT',
            'fixed_amount' => '1.000',
            'applies_to' => 'DOCUMENT_TOTAL',
            'sequence_order' => 90,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => true,
        ]);
        $document = Document::factory()->create([
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-H-BYTES',
            'currency' => 'TND',
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Byte-stable item',
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '0',
            'line_total' => '100.000',
        ]);
        $document->load('lines');

        $baseline = $this->service->calculateDocumentTaxes($document);
        $hashService = new FiscalHashService;
        $baselineBytes = $hashService->serializeForHashing([
            'document_number' => $document->document_number,
            'posted_at' => '2026-08-10T00:00:00Z',
            'total' => $baseline->total,
            'currency' => $document->currency,
        ]);

        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'Generic document surcharge',
            'code' => 'GEN_DOC_TOTAL_BYTES',
            'tax_type' => 'FIXED_AMOUNT',
            'fixed_amount' => '7.000',
            'applies_to' => 'DOCUMENT_TOTAL',
            'sequence_order' => 91,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);

        $withInvalidBrownfieldRow = $this->service->calculateDocumentTaxes($document);
        $withInvalidRowBytes = $hashService->serializeForHashing([
            'document_number' => $document->document_number,
            'posted_at' => '2026-08-10T00:00:00Z',
            'total' => $withInvalidBrownfieldRow->total,
            'currency' => $document->currency,
        ]);

        $this->assertSame('1.000', $baseline->documentTaxTotal);
        $this->assertSame('101.000', $baseline->total);
        $this->assertSame('INV-H-BYTES|2026-08-10T00:00:00Z|101.000|TND', $baselineBytes);
        $this->assertSame($baseline->toArray(), $withInvalidBrownfieldRow->toArray());
        $this->assertSame($baselineBytes, $withInvalidRowBytes);
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
