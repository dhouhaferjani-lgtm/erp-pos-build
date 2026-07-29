<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class BatchAddProductsValidationTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_BARCODE = '619400001001';

    private const UNKNOWN_BARCODE = '619400009999';

    private Tenant $tenant;

    private Company $company;

    private Company $otherCompany;

    private User $admin;

    private Product $product;

    private Product $otherCompanyProduct;

    private InventoryCounting $counting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Batch product validation tenant',
            'slug' => 'batch-product-validation',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = $this->createCompany('Primary', 'BATCH-PRIMARY');
        $this->otherCompany = $this->createCompany('Other', 'BATCH-OTHER');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Batch validation admin',
            'email' => 'batch-validation@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->admin->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = $this->createProduct($this->company, 'BATCH-VALID', self::PRODUCT_BARCODE);
        $this->otherCompanyProduct = $this->createProduct($this->otherCompany, 'BATCH-OTHER', '619400001002');

        $this->counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => (string) $this->admin->id,
            'status' => CountingStatus::Draft,
            'scope_type' => 'product',
            'scope_filters' => ['product_ids' => []],
            'execution_mode' => 'sequential',
        ]);
    }

    public function test_barcode_sent_as_product_id_is_reported_per_item_and_not_persisted(): void
    {
        $response = $this->postBatch([
            ['productId' => self::PRODUCT_BARCODE],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', [])
            ->assertJsonPath('data.errors.0.data.productId', self::PRODUCT_BARCODE)
            ->assertJsonPath('data.errors.0.code', 'PRODUCT_NOT_FOUND')
            ->assertJsonPath('data.errors.0.error', 'Invalid product ID; expected a UUID')
            ->assertJsonPath('data.total_products', 0);

        $this->assertSame([], $this->persistedProductIds());
    }

    public function test_well_formed_product_id_from_another_company_is_reported_and_not_persisted(): void
    {
        $response = $this->postBatch([
            ['productId' => (string) $this->otherCompanyProduct->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', [])
            ->assertJsonPath('data.errors.0.data.productId', (string) $this->otherCompanyProduct->id)
            ->assertJsonPath('data.errors.0.code', 'PRODUCT_NOT_FOUND')
            ->assertJsonPath('data.errors.0.error', 'Product not found for current company')
            ->assertJsonPath('data.total_products', 0);

        $this->assertSame([], $this->persistedProductIds());
    }

    public function test_valid_product_id_still_succeeds(): void
    {
        $response = $this->postBatch([
            ['productId' => (string) $this->product->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success.0.productId', (string) $this->product->id)
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.total_products', 1);

        $this->assertSame([(string) $this->product->id], $this->persistedProductIds());
    }

    public function test_uppercase_uuid_resolves_to_the_canonical_company_product_id(): void
    {
        $response = $this->postBatch([
            ['productId' => strtoupper((string) $this->product->id)],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success.0.productId', (string) $this->product->id)
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.total_products', 1);

        $this->assertSame([(string) $this->product->id], $this->persistedProductIds());
    }

    public function test_barcode_without_product_id_still_resolves(): void
    {
        $response = $this->postBatch([
            ['barcode' => self::PRODUCT_BARCODE],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success.0.productId', (string) $this->product->id)
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.total_products', 1);

        $this->assertSame([(string) $this->product->id], $this->persistedProductIds());
    }

    public function test_empty_product_id_preserves_barcode_fallback(): void
    {
        $response = $this->postBatch([
            ['productId' => '', 'barcode' => self::PRODUCT_BARCODE],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success.0.productId', (string) $this->product->id)
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.total_products', 1);

        $this->assertSame([(string) $this->product->id], $this->persistedProductIds());
    }

    public function test_barcode_lookup_miss_returns_typed_code_and_unchanged_message(): void
    {
        $response = $this->postBatch([
            ['barcode' => self::UNKNOWN_BARCODE],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', [])
            ->assertJsonPath('data.errors.0.code', 'PRODUCT_NOT_FOUND')
            ->assertJsonPath(
                'data.errors.0.error',
                'Product not found with barcode: '.self::UNKNOWN_BARCODE,
            )
            ->assertJsonPath('data.total_products', 0);

        $this->assertSame([], $this->persistedProductIds());
    }

    public function test_non_string_barcode_returns_typed_code_and_unchanged_message(): void
    {
        $response = $this->postBatch([
            ['barcode' => 12345],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', [])
            ->assertJsonPath('data.errors.0.data.barcode', 12345)
            ->assertJsonPath('data.errors.0.code', 'INVALID_BARCODE')
            ->assertJsonPath('data.errors.0.error', 'Invalid barcode; expected a string')
            ->assertJsonPath('data.total_products', 0);

        $this->assertSame([], $this->persistedProductIds());
    }

    public function test_duplicate_product_returns_typed_code_and_unchanged_message(): void
    {
        $this->counting->scope_filters = [
            'product_ids' => [(string) $this->product->id],
        ];
        $this->counting->save();

        $response = $this->postBatch([
            ['productId' => (string) $this->product->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', [])
            ->assertJsonPath('data.errors.0.code', 'PRODUCT_ALREADY_IN_COUNT')
            ->assertJsonPath('data.errors.0.error', 'Product already added to this count')
            ->assertJsonPath('data.total_products', 1);

        $this->assertSame([(string) $this->product->id], $this->persistedProductIds());
    }

    public function test_mixed_batch_persists_valid_products_and_reports_invalid_items(): void
    {
        $response = $this->postBatch([
            ['productId' => (string) $this->product->id],
            ['productId' => '619400009999'],
            ['productId' => 619400009998],
            ['productId' => (string) $this->otherCompanyProduct->id],
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data.success')
            ->assertJsonCount(3, 'data.errors')
            ->assertJsonPath('data.success.0.productId', (string) $this->product->id)
            ->assertJsonPath('data.errors.0.code', 'PRODUCT_NOT_FOUND')
            ->assertJsonPath('data.errors.0.error', 'Invalid product ID; expected a UUID')
            ->assertJsonPath('data.errors.1.data.productId', 619400009998)
            ->assertJsonPath('data.errors.1.code', 'PRODUCT_NOT_FOUND')
            ->assertJsonPath('data.errors.2.code', 'PRODUCT_NOT_FOUND')
            ->assertJsonPath('data.errors.2.error', 'Product not found for current company')
            ->assertJsonPath('data.total_products', 1);

        $this->assertSame([(string) $this->product->id], $this->persistedProductIds());
    }

    /**
     * @param  list<array{productId?: string|int, barcode?: string|int}>  $products
     * @return TestResponse<Response>
     */
    private function postBatch(array $products): TestResponse
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        return $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/inventory/countings/{$this->counting->id}/add-products/batch", [
                'products' => $products,
            ]);
    }

    /** @return list<string> */
    private function persistedProductIds(): array
    {
        $this->counting->refresh();

        return $this->counting->scope_filters['product_ids'] ?? [];
    }

    private function createCompany(string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name.' Batch Company',
            'legal_name' => $name.' Batch Company LLC',
            'tax_id' => $taxId,
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createProduct(Company $company, string $sku, string $barcode): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $sku.' product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
    }
}
