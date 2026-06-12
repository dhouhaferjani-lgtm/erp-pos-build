<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T12: Verify that ProductService::upsert resolves a default tax rate
 * when no explicit tax_rate is provided, so no product persists with NULL.
 */
final class ProductUpsertDefaultTaxTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $service;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Resolve via container so autowiring of TaxResolutionService works.
        $this->service = $this->app->make(ProductService::class);

        $this->tenant = Tenant::create([
            'name' => 'Tax Import Test Tenant',
            'slug' => 'tax-import-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tax Import Test Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
            'default_tax_rate' => '19.00',
        ]);
    }

    /**
     * Test A: No tax_rate in data → company default is used.
     */
    public function test_upsert_with_no_tax_rate_inherits_company_default(): void
    {
        $id = $this->service->upsert(
            $this->tenant->id,
            $this->company->id,
            ['name' => 'Imported Product', 'sku' => 'IMP-001']
        );

        $product = Product::findOrFail($id);
        $this->assertSame(0, bccomp((string) $product->tax_rate, '19.00', 2));
    }

    /**
     * Test B: category_name given and category has a default_tax_rate → category rate is used.
     */
    public function test_upsert_with_category_name_inherits_category_default_tax(): void
    {
        $category = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Parapharmacy',
            'default_tax_rate' => '7.00',
        ]);

        // Sanity — category row has the rate
        $this->assertSame(0, bccomp((string) $category->default_tax_rate, '7.00', 2));

        $id = $this->service->upsert(
            $this->tenant->id,
            $this->company->id,
            ['name' => 'Serum X', 'sku' => 'SERA-001', 'category_name' => 'Parapharmacy']
        );

        $product = Product::findOrFail($id);
        $this->assertSame(0, bccomp((string) $product->tax_rate, '7.00', 2));
    }

    /**
     * Test C: explicit tax_rate in data is preserved as-is.
     */
    public function test_upsert_preserves_explicit_tax_rate(): void
    {
        $id = $this->service->upsert(
            $this->tenant->id,
            $this->company->id,
            ['name' => 'Custom Tax Product', 'sku' => 'CTX-001', 'tax_rate' => '13.00']
        );

        $product = Product::findOrFail($id);
        $this->assertSame(0, bccomp((string) $product->tax_rate, '13.00', 2));
    }

    /**
     * Test D: re-importing an existing product WITHOUT a tax_rate column in the data
     * must NOT clobber the stored explicit rate with the company default.
     *
     * Scenario:
     *   company default_tax_rate = 19.00
     *   First upsert: sku P1 with explicit tax_rate = 7.00  → stored rate = 7.00
     *   Second upsert: sku P1, NO tax_rate key, name changed → stored rate must still be 7.00, NOT 19.00
     */
    public function test_reimport_without_tax_rate_preserves_existing_stored_rate(): void
    {
        // First import — explicit rate 7.00 wins over the company default 19.00
        $this->service->upsert(
            $this->tenant->id,
            $this->company->id,
            ['name' => 'Preserved Rate Product', 'sku' => 'PRV-001', 'tax_rate' => '7.00']
        );

        // Second import — same SKU, no tax_rate supplied, only name changed
        $idAfterReimport = $this->service->upsert(
            $this->tenant->id,
            $this->company->id,
            ['name' => 'Preserved Rate Product (updated)', 'sku' => 'PRV-001']
        );

        $product = Product::findOrFail($idAfterReimport);

        // Must still be 7.00 — not reset to the company default 19.00
        $this->assertSame(
            0,
            bccomp((string) $product->tax_rate, '7.00', 2),
            "Expected tax_rate to remain 7.00 after re-import, got {$product->tax_rate}"
        );
    }
}
