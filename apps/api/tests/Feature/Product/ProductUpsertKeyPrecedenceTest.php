<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ProductUpsertKeyPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $service;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(ProductService::class);

        $this->tenant = Tenant::create([
            'name' => 'Product Upsert Test Tenant',
            'slug' => 'product-upsert-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Product Upsert Test Company',
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

    public function test_blank_sku_matches_existing_product_by_barcode_and_keeps_existing_sku(): void
    {
        $existing = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Old Name',
            'sku' => 'SKU-Y',
            'barcode' => '3216549870123',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);

        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'New Name',
            'sku' => '',
            'barcode' => '3216549870123',
        ]);

        $this->assertSame($existing->id, $id);
        $existing->refresh();
        $this->assertSame('New Name', $existing->name);
        $this->assertSame('SKU-Y', $existing->sku);
        $this->assertSame(1, Product::where('company_id', $this->company->id)->count());
    }

    public function test_blank_sku_with_barcode_creates_product_using_barcode_as_sku(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Barcode Product',
            'sku' => '',
            'barcode' => '1234567890123',
        ]);

        $product = Product::findOrFail($id);
        $this->assertSame('1234567890123', $product->sku);
    }

    public function test_blank_sku_without_barcode_uses_name_slug_and_reimport_is_idempotent(): void
    {
        $firstId = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Crème Solaire 50',
            'sku' => '',
        ]);

        $secondId = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Crème Solaire 50',
            'sku' => '',
        ]);

        $product = Product::findOrFail($firstId);
        $this->assertSame($firstId, $secondId);
        $this->assertSame('CREME-SOLAIRE-50', $product->sku);
        $this->assertSame(1, Product::where('company_id', $this->company->id)->count());
    }

    public function test_missing_type_defaults_to_part(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Default Type Product',
            'sku' => 'DEF-TYPE',
        ]);

        $product = Product::findOrFail($id);
        $this->assertSame(ProductType::Part, $product->type);
    }

    public function test_upsert_refuses_a_sku_held_by_a_soft_deleted_product(): void
    {
        $holder = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Deleted Holder',
            'sku' => 'DELETED-HOLDER',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);
        $holder->delete();

        try {
            $this->service->upsert($this->tenant->id, $this->company->id, [
                'name' => 'Replacement Product',
                'sku' => 'DELETED-HOLDER',
            ]);
            $this->fail('A soft-deleted product must retain its SKU until explicitly restored or purged.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('sku_held_by_deleted_product:', $exception->getMessage());
            $this->assertStringContainsString('DELETED-HOLDER', $exception->getMessage());
            $this->assertStringContainsString('soft-deleted product', $exception->getMessage());
            $this->assertStringContainsString('purge the deleted record or choose a different SKU', $exception->getMessage());
            $this->assertStringNotContainsString('restore', $exception->getMessage());
        }

        $this->assertTrue($holder->fresh()?->trashed() ?? false);
        $this->assertSame(1, Product::withTrashed()->where('sku', 'DELETED-HOLDER')->count());
    }

    /**
     * Gate r2 G3A-R2-1(a). `products.barcode` carries no unique index at ANY
     * scope, so a trashed row that merely shares a BARCODE forbids nothing. The
     * guard must key on the SKU that will be written (`XYZ-BARCODE`), which no
     * row holds — the trashed twin holds `TRASHED-ABC`, a SKU the operator never
     * supplied and must never see in a row error.
     */
    public function test_a_trashed_barcode_twin_holding_a_different_sku_does_not_block_the_row(): void
    {
        $twin = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Trashed Barcode Twin',
            'sku' => 'TRASHED-ABC',
            'barcode' => 'XYZ-BARCODE',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);
        $twin->delete();

        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Live Replacement',
            'sku' => '',
            'barcode' => 'XYZ-BARCODE',
        ]);

        $product = Product::findOrFail($id);
        $this->assertNotSame($twin->id, $product->id);
        $this->assertSame('XYZ-BARCODE', $product->sku);
        $this->assertSame('XYZ-BARCODE', $product->barcode);
        $this->assertTrue($twin->fresh()?->trashed() ?? false);
    }

    /**
     * Gate r2 G3A-R2-1(b). The barcode match finds nothing, but the SKU derived
     * FROM that barcode is held by a trashed row and the lifetime
     * `unique(company_id, sku)` will reject the INSERT. The operator must get the
     * coded token, never a raw 23505 in `import_rows.import_error`.
     */
    public function test_a_barcode_derived_sku_held_by_a_trashed_product_is_refused_with_the_coded_token(): void
    {
        $holder = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Deleted Barcode-as-SKU Holder',
            'sku' => 'XYZ-BARCODE',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);
        $holder->delete();

        try {
            $this->service->upsert($this->tenant->id, $this->company->id, [
                'name' => 'Barcode Replacement',
                'sku' => '',
                'barcode' => 'XYZ-BARCODE',
            ]);
            $this->fail('A SKU derived from the barcode and held by a trashed product must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(QueryException::class, $exception);
            $this->assertStringStartsWith('sku_held_by_deleted_product:', $exception->getMessage());
            $this->assertStringContainsString('XYZ-BARCODE', $exception->getMessage());
            $this->assertStringContainsString('purge the deleted record or choose a different SKU', $exception->getMessage());
            $this->assertStringNotContainsString('restore', $exception->getMessage());
        }

        $this->assertSame(0, Product::query()->where('company_id', $this->company->id)->count());
    }

    /**
     * Gate r2 G3A-R2-1(b), name-derived leg: no SKU and no barcode, so the
     * written value is the name slug. Same lifetime check, same coded token.
     */
    public function test_a_name_derived_sku_held_by_a_trashed_product_is_refused_with_the_coded_token(): void
    {
        $holder = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Crème Solaire 50',
            'sku' => 'CREME-SOLAIRE-50',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);
        $holder->delete();

        try {
            $this->service->upsert($this->tenant->id, $this->company->id, [
                'name' => 'Crème Solaire 50',
                'sku' => '',
            ]);
            $this->fail('A name-derived SKU held by a trashed product must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(QueryException::class, $exception);
            $this->assertStringStartsWith('sku_held_by_deleted_product:', $exception->getMessage());
            $this->assertStringContainsString('CREME-SOLAIRE-50', $exception->getMessage());
            $this->assertStringNotContainsString('restore', $exception->getMessage());
        }

        $this->assertSame(0, Product::query()->where('company_id', $this->company->id)->count());
    }

    public function test_find_id_by_sku_refuses_a_soft_deleted_holder(): void
    {
        $holder = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Deleted Lookup Holder',
            'sku' => 'DELETED-LOOKUP',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);
        $holder->delete();

        try {
            $this->service->findIdBySku($this->tenant->id, $this->company->id, 'DELETED-LOOKUP');
            $this->fail('findIdBySku must expose a soft-deleted holder as a refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('sku_held_by_deleted_product:', $exception->getMessage());
            $this->assertStringContainsString('DELETED-LOOKUP', $exception->getMessage());
            $this->assertStringContainsString('soft-deleted product', $exception->getMessage());
            $this->assertStringNotContainsString('restore', $exception->getMessage());
        }
    }

    public function test_brand_name_is_reused_and_linked_to_products(): void
    {
        $firstId = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Nivea Cream',
            'sku' => 'NIVEA-1',
            'brand' => 'Nivea',
        ]);

        $secondId = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Nivea Lotion',
            'sku' => 'NIVEA-2',
            'brand' => 'Nivea',
        ]);

        $first = Product::findOrFail($firstId);
        $second = Product::findOrFail($secondId);

        $this->assertSame(1, Brand::where('tenant_id', $this->tenant->id)->count());
        $this->assertNotNull($first->brand_id);
        $this->assertSame($first->brand_id, $second->brand_id);
    }
}
