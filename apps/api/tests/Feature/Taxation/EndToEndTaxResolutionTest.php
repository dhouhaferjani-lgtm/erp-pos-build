<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end integration test for the tax provisioning + writer + calculation chain.
 *
 * Proves: tenant provisioned via CompanyTaxProvisioningService → product created
 * WITHOUT an explicit tax rate inherits the company default → TaxCalculationService
 * yields NON-ZERO tax on a document line using that resolved rate.
 */
final class EndToEndTaxResolutionTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Step 1 – tenant + TN company, then provision tax
    // -------------------------------------------------------------------------

    public function test_provisioned_tn_company_product_without_explicit_rate_yields_non_zero_tax(): void
    {
        // Arrange: seed countries (required for FK) then create a TN company.
        (new CountriesSeeder)->run();

        $tenant = Tenant::create([
            'name' => 'Demo Tunisia',
            'slug' => 'demo-tn-e2e-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => 'TN',
            'currency_code' => 'TND',
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'E2E Tunisia Co',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        // ---- Step 2: provision tax ------------------------------------------
        $this->app->make(CompanyTaxProvisioningService::class)
            ->provisionForCompany($company, failLoudOnMissingCountry: true);

        $company->refresh();

        // Guard: provisioning must have set a default config + rate.
        $this->assertNotNull(
            $company->default_tax_configuration_id,
            'Provisioning must set company.default_tax_configuration_id',
        );
        $this->assertEquals(
            '19.00',
            $company->default_tax_rate,
            'TN default tax rate must be 19.00 after provisioning',
        );

        // ---- Step 3: create product WITHOUT explicit tax_rate ----------------
        /** @var ProductService $productService */
        $productService = $this->app->make(ProductService::class);

        $productId = $productService->upsert($tenant->id, $company->id, [
            'name' => 'Crème hydratante',
            'sku' => 'CR1',
            // No tax_rate key → service must resolve from company default
        ]);

        $product = Product::findOrFail($productId);

        // Guard: writer must have resolved the company default (19.00).
        // bccomp is driver-agnostic: '19' (SQLite) == '19.00' (PG) both pass.
        $this->assertSame(
            0,
            bccomp((string) $product->tax_rate, '19.00', 2),
            "Product tax_rate must resolve to 19.00; got: {$product->tax_rate}",
        );

        // ---- Step 4: tax calculation on a document line with that rate -------
        $partner = Partner::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => 'customer',
            'code' => 'CUST-E2E',
            'name' => 'Test Customer E2E',
        ]);

        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'document_date' => now(),
            'document_number' => 'INV-E2E-001',
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '120.000',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'document_id' => $document->id,
            'description' => $product->name,
            'quantity' => '1',
            'unit_price' => '100.00',
            // Use the rate that was resolved and persisted on the product —
            // proving the resolved value flows through to the tax calculation.
            'tax_rate' => $product->tax_rate,
            'line_total' => '100.00',
            'line_number' => 1,
        ]);

        $document->refresh();

        // Bind company context so TaxCalculationService resolves TND → scale 3.
        app(CompanyContext::class)->setCompanyId($company->id);

        /** @var TaxCalculationService $taxService */
        $taxService = $this->app->make(TaxCalculationService::class);
        $result = $taxService->calculateDocumentTaxes($document);

        // ---- Core assertion: tax must be non-zero ---------------------------
        $this->assertGreaterThan(
            0,
            bccomp($result->totalTaxAmount, '0', 3),
            "totalTaxAmount must be > 0; got: {$result->totalTaxAmount}",
        );

        // ---- Precision assertion: should be ≈ 19% of 100.00 (+ 1 TND stamp) -
        // Line VAT: 100 × 19% = 19.000  (TND scale 3)
        $this->assertSame(
            0,
            bccomp($result->lineTaxAmount, '19.000', 3),
            "Line VAT must be 19.000 TND; got: {$result->lineTaxAmount}",
        );

        // Total tax includes VAT (19.000) + stamp duty (1.000) = 20.000
        $this->assertSame(
            0,
            bccomp($result->totalTaxAmount, '20.000', 3),
            "Total tax (VAT+stamp) must be 20.000 TND; got: {$result->totalTaxAmount}",
        );
    }
}
