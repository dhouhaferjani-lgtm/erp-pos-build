<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * M4 adversarial-review: ensure `has_variants` is correctly serialised
 * in the GET /api/v1/products (POS catalog) feed.
 *
 * Coverage:
 *   - A product with at least one ACTIVE variant → has_variants: true.
 *   - A product with NO variants → has_variants: false.
 *   - A product whose only variant is INACTIVE → has_variants: false.
 *   - The field is present on every item in the response.
 */
class ProductHasVariantsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Variant Test Tenant',
            'slug' => 'variant-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Variant Test Company',
            'legal_name' => 'Variant Test Company LLC',
            'tax_id' => 'VT-TAX001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Variant Test User',
            'email' => 'variant-test@example.com',
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

    public function test_product_with_active_variant_returns_has_variants_true(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Shoe With Sizes',
            'sku' => 'SHOE-001',
        ]);

        ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'SHOE-001-39',
            'sku' => 'SHOE-001-39',
            'name_suffix' => '— Size 39',
            'is_active' => true,
            'is_default' => true,
            'display_order' => 1,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($item, 'Product should appear in the list response');
        $this->assertTrue($item['has_variants'], 'has_variants must be true when an active variant exists');
    }

    public function test_product_with_no_variants_returns_has_variants_false(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Simple Widget',
            'sku' => 'WGT-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($item, 'Product should appear in the list response');
        $this->assertFalse($item['has_variants'], 'has_variants must be false when no variants exist');
    }

    public function test_product_with_only_inactive_variant_returns_has_variants_false(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Discontinued Colour Product',
            'sku' => 'CLR-001',
        ]);

        ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'CLR-001-RED',
            'sku' => 'CLR-001-RED',
            'name_suffix' => '— Red (discontinued)',
            'is_active' => false,
            'is_default' => false,
            'display_order' => 1,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($item, 'Product should appear in the list response');
        $this->assertFalse($item['has_variants'], 'has_variants must be false when all variants are inactive');
    }

    public function test_has_variants_field_is_present_in_response(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Any Product',
            'sku' => 'ANY-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'sku', 'has_variants'],
                ],
            ]);
    }
}
