<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Taxation\Domain\Entities\StampDutyRule;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\TunisiaStampDutySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxCalculationTest extends TestCase
{
    use RefreshDatabase;

    private Company $frenchCompany;
    private Company $tunisianCompany;
    private Tenant $tenant;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed countries first (required for foreign key constraint)
        $this->seed(\Database\Seeders\CountriesSeeder::class);

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'country_code' => 'TN',
            'currency_code' => 'TND',
            'status' => 'active',
            'plan' => 'professional',
        ]);

        // Create French company (no stamp duty)
        $this->frenchCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'French Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '20.00',
        ]);

        // Create Tunisian company (with stamp duty)
        $this->tunisianCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tunisian Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);

        // Seed Tunisia stamp duty rules
        $this->seed(TunisiaStampDutySeeder::class);

        // Create a dummy partner for documents
        $this->partner = Partner::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'type' => 'customer',
            'code' => 'CUST001',
            'name' => 'Test Customer',
        ]);
    }

    public function test_it_calculates_vat_correctly_for_french_invoice(): void
    {
        // Arrange: Create a French invoice with 20% VAT
        $invoice = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->frenchCompany->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'document_date' => now(),
            'document_number' => 'INV-FR-001',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        // Add invoice lines with VAT
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->frenchCompany->id,
            'document_id' => $invoice->id,
            'description' => 'Product A',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
            'line_number' => 1,
        ]);

        // Refresh to load relationships
        $invoice->refresh();

        // Act: Calculate taxes
        $service = app(TaxCalculationService::class);
        $result = $service->calculateDocumentTaxes($invoice);

        // Assert: VAT calculated correctly, no stamp duty for France
        $this->assertEquals('20.00', $result->lineTaxAmount, 'Line tax (VAT 20%) should be 20.00');
        $this->assertEquals('0.000', $result->stampDutyAmount, 'France should have no stamp duty');
        $this->assertEquals('20.00', $result->totalTaxAmount, 'Total tax should be 20.00 (VAT only)');
        $this->assertEquals('120.00', $result->total, 'Total should be 120.00 (100 + 20 VAT)');
        $this->assertCount(1, $result->taxDetails, 'Should have only 1 tax detail (VAT)');
        $this->assertFalse($result->taxDetails[0]->isStampDuty, 'First detail should be VAT, not stamp duty');
    }

    public function test_it_calculates_stamp_duty_for_tunisian_invoice(): void
    {
        // Arrange: Create a Tunisian posted invoice
        $invoice = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'document_date' => now(),
            'document_number' => 'INV-TN-001',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '120.000', // 100 + 19 VAT + 1 stamp duty
        ]);

        // Add invoice line with Tunisian VAT (19%)
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'document_id' => $invoice->id,
            'description' => 'Product B',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
            'line_number' => 1,
        ]);

        // Refresh to load relationships
        $invoice->refresh();

        // Act: Calculate taxes
        $service = app(TaxCalculationService::class);
        $result = $service->calculateDocumentTaxes($invoice);

        // Assert: VAT + Stamp Duty calculated
        $this->assertEquals('19.00', $result->lineTaxAmount, 'Line tax (VAT 19%) should be 19.00');
        $this->assertEquals('1.000', $result->stampDutyAmount, 'Tunisia should have 1.000 TND stamp duty');
        $this->assertEquals('20.00', $result->totalTaxAmount, 'Total tax should be 20.00 (19 VAT + 1 stamp)');
        $this->assertEquals('120.00', $result->total, 'Total should be 120.00 (100 + 19 + 1)');
        $this->assertCount(2, $result->taxDetails, 'Should have 2 tax details (VAT + stamp duty)');
        $this->assertTrue($result->taxDetails[1]->isStampDuty, 'Second detail should be stamp duty');
    }

    public function test_it_does_not_apply_stamp_duty_for_drafts(): void
    {
        // Arrange: Create a Tunisian DRAFT invoice (not posted)
        $invoice = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft, // DRAFT status
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'document_date' => now(),
            // Drafts don't have document_number yet (will be assigned on post)
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00', // No stamp duty yet
        ]);

        // Add invoice line
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'document_id' => $invoice->id,
            'description' => 'Product C',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
            'line_number' => 1,
        ]);

        // Refresh to load relationships
        $invoice->refresh();

        // Act: Calculate taxes
        $service = app(TaxCalculationService::class);
        $result = $service->calculateDocumentTaxes($invoice);

        // Assert: VAT calculated but NO stamp duty (because draft)
        $this->assertEquals('19.00', $result->lineTaxAmount, 'Line tax (VAT 19%) should be 19.00');
        $this->assertEquals('0.000', $result->stampDutyAmount, 'Draft invoices should NOT have stamp duty');
        $this->assertEquals('19.00', $result->totalTaxAmount, 'Total tax should be 19.00 (VAT only)');
        $this->assertEquals('119.00', $result->total, 'Total should be 119.00 (100 + 19, no stamp)');
        $this->assertCount(1, $result->taxDetails, 'Should have only 1 tax detail (VAT)');
        $this->assertFalse($result->taxDetails[0]->isStampDuty, 'First detail should be VAT, not stamp duty');
    }
}
