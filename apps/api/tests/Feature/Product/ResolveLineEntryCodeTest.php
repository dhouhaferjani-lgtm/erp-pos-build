<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Catalog\ProductVariantFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ResolveLineEntryCodeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Line Entry Tenant',
            'slug' => 'line-entry-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Line Entry Company',
            'legal_name' => 'Line Entry Company LLC',
            'tax_id' => 'LINE123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Line Entry User',
            'email' => 'line-entry@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_resolves_product_barcode_before_product_sku_and_variant_codes(): void
    {
        $productBarcode = $this->createProduct([
            'name' => 'Barcode Match',
            'sku' => 'SKU-OTHER',
            'barcode' => 'LINE-CODE',
        ]);
        $productSku = $this->createProduct([
            'name' => 'Sku Match',
            'sku' => 'LINE-CODE',
            'barcode' => 'BAR-OTHER',
        ]);
        $variantProduct = $this->createProduct([
            'name' => 'Variant Parent',
            'sku' => 'VAR-PARENT',
            'barcode' => 'VAR-PARENT-BC',
        ]);
        ProductVariantFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $variantProduct->id,
            'sku' => 'VAR-SKU-OTHER',
            'barcode' => 'LINE-CODE',
            'variant_code' => 'VAR-LINE-CODE',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/line-entry/resolve-code?code=LINE-CODE');

        $response->assertOk()
            ->assertJsonPath('data.kind', 'product')
            ->assertJsonPath('data.matched_code_type', 'product_barcode')
            ->assertJsonPath('data.product.id', $productBarcode->id)
            ->assertJsonMissingPath('data.variant');

        $this->assertNotSame($productSku->id, $response->json('data.product.id'));
    }

    public function test_resolves_product_sku_before_variant_codes(): void
    {
        $product = $this->createProduct([
            'name' => 'Sku Product',
            'sku' => 'SKU-FIRST',
            'barcode' => 'BAR-FIRST',
        ]);
        $variantProduct = $this->createProduct([
            'name' => 'Variant Parent',
            'sku' => 'VAR-PARENT',
            'barcode' => 'VAR-PARENT-BC',
        ]);
        ProductVariantFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $variantProduct->id,
            'sku' => 'SKU-FIRST',
            'barcode' => 'VAR-BC-FIRST',
            'variant_code' => 'VAR-SKU-FIRST',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/line-entry/resolve-code?code=SKU-FIRST');

        $response->assertOk()
            ->assertJsonPath('data.kind', 'product')
            ->assertJsonPath('data.matched_code_type', 'product_sku')
            ->assertJsonPath('data.product.id', $product->id);
    }

    public function test_resolves_active_variant_barcode_with_parent_product(): void
    {
        $product = $this->createProduct([
            'name' => 'Variant Barcode Parent',
            'sku' => 'VAR-PARENT-001',
            'barcode' => 'PARENT-001',
        ]);
        $variant = ProductVariantFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'VAR-SKU-001',
            'barcode' => 'VAR-BC-001',
            'variant_code' => 'VAR-001',
            'name_suffix' => 'Large',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/line-entry/resolve-code?code=VAR-BC-001');

        $response->assertOk()
            ->assertJsonPath('data.kind', 'variant')
            ->assertJsonPath('data.matched_code_type', 'variant_barcode')
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.variant.id', $variant->id)
            ->assertJsonPath('data.variant.product_id', $product->id);
    }

    public function test_returns_not_found_for_unknown_code(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/line-entry/resolve-code?code=MISSING-CODE');

        $response->assertOk()
            ->assertJsonPath('data.kind', 'not_found')
            ->assertJsonPath('data.code', 'MISSING-CODE');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProduct(array $attributes): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Line Product',
            'sku' => 'LINE-SKU',
            'barcode' => null,
            'is_active' => true,
        ], $attributes));
    }
}
