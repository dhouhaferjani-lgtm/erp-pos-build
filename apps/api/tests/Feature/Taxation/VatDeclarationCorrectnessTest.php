<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Certification-critical VAT-reporting correctness lane
 * (docs/superpowers/tickets/2026-08-02-documents-gate-followups.md
 * "VAT-reporting findings" + docs/superpowers/reviews/2026-08-02-documents-fixlane-gate.md
 * F4/F5).
 *
 * Exercises the FULL producer→consumer chain: TaxCalculationService snapshots
 * document_tax_details, EloquentVatDataRepository (the real VAT-declaration
 * consumer) reads them back. A producer-only unit test cannot catch a defect
 * where the producer is "fixed" but a consumer still relies on the old
 * (wrong) semantics — this class proves both sides agree.
 */
class VatDeclarationCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private TaxCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'VAT Declaration Test Tenant',
            'slug' => 'vat-declaration-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT Declaration Test Company',
            'legal_name' => 'VAT Declaration Test Company SARL',
            'tax_id' => 'VAT654321',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        $this->seed(TunisiaTaxConfigurationSeeder::class);

        $this->service = app(TaxCalculationService::class);
    }

    /**
     * DEFECT 1, at the declaration-aggregation level: a VAT period sums
     * SUM(document_tax_details.tax_base) per rate across every document in
     * the period. Before the fix, every rate row on every document
     * snapshotted the WHOLE document subtotal, so this sum was inflated by
     * a multiple of the true taxed base. Across TWO documents, each with
     * TWO taxed rates (19% + 7%, no untaxed lines), the declared base per
     * rate must equal the actual net taxed at that rate, and the total
     * declared base must equal the sum of both documents' subtotals exactly
     * — not a multiple of it.
     */
    public function test_vat_period_aggregation_declared_base_equals_actual_taxed_bases_across_mixed_documents(): void
    {
        // Isolate this test to DEFECT 1 (tax_base): deactivate the TN stamp
        // duties so no DOCUMENT_TOTAL row is generated at all, and the
        // aggregation invariant does not also depend on stamp-vs-VAT
        // separation (a distinct, separately-ticketed defect).
        TaxConfiguration::where('country_code', 'TN')
            ->where('is_stamp_duty', true)
            ->update(['is_active' => false]);

        $invoice1 = $this->createTaxInvoice('2026-03-05');
        $this->addLine($invoice1, unitPrice: '100.000', taxRate: '19.00');
        $this->addLine($invoice1, unitPrice: '200.000', taxRate: '7.00');
        $invoice1->load('lines');
        $result1 = $this->service->calculateDocumentTaxes($invoice1);
        $this->service->snapshotTaxDetails($invoice1, $result1);

        $invoice2 = $this->createTaxInvoice('2026-03-20');
        $this->addLine($invoice2, unitPrice: '50.000', taxRate: '19.00');
        $this->addLine($invoice2, unitPrice: '150.000', taxRate: '7.00');
        $invoice2->load('lines');
        $result2 = $this->service->calculateDocumentTaxes($invoice2);
        $this->service->snapshotTaxDetails($invoice2, $result2);

        $this->assertSame('300.000', $result1->subtotal);
        $this->assertSame('200.000', $result2->subtotal);

        $repository = app(VatDataRepositoryInterface::class);
        $aggregations = $repository->aggregateByRateAndDirection(
            $this->company->id,
            '2026-03-01',
            '2026-03-31',
        );

        // Only the two real VAT rates should reach the declaration.
        $this->assertCount(2, $aggregations);

        $byRate = [];
        foreach ($aggregations as $aggregation) {
            $byRate[$aggregation->taxRate] = $aggregation;
        }

        // 19% bucket: 100.000 (invoice1) + 50.000 (invoice2) = 150.000 —
        // NOT 300.000 + 200.000 = 500.000 (the whole-subtotal-per-row bug).
        $this->assertSame('150.000', $byRate['19.00']->baseAmount);
        $this->assertSame('28.500', $byRate['19.00']->vatAmount);

        // 7% bucket: 200.000 (invoice1) + 150.000 (invoice2) = 350.000.
        $this->assertSame('350.000', $byRate['7.00']->baseAmount);
        $this->assertSame('24.500', $byRate['7.00']->vatAmount);

        // Every line across both documents carries a taxed rate (no exempt
        // lines), so the declared base must sum EXACTLY to the sum of both
        // documents' subtotals — the actual taxed base.
        $declaredBase = bcadd($byRate['19.00']->baseAmount, $byRate['7.00']->baseAmount, 3);
        $actualTaxedBase = bcadd($result1->subtotal, $result2->subtotal, 3);
        $this->assertSame($actualTaxedBase, $declaredBase);
        $this->assertSame('500.000', $declaredBase);
    }

    private function createTaxInvoice(string $documentDate): Document
    {
        $number = 'INV-'.now()->format('Y').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => $documentDate,
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
            // A SEALED fiscal document must carry fiscal core
            // (chk_fiscal_mandatory_core, enforced by PostgreSQL).
            'fiscal_hash' => hash('sha256', 'vat-decl-'.$number),
            'chain_sequence' => 1,
        ]);
    }

    private function addLine(Document $document, string $unitPrice, string $taxRate): DocumentLine
    {
        $lineNumber = DocumentLine::where('document_id', $document->id)->count() + 1;

        return DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => $lineNumber,
            'description' => 'Test item',
            'quantity' => '1',
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'line_total' => $unitPrice,
        ]);
    }
}
