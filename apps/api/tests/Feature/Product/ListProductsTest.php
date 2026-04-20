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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListProductsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
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
            'name' => 'Test User',
            'email' => 'user@example.com',
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

    public function test_can_list_products(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product One',
            'sku' => 'PRD-001',
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product Two',
            'sku' => 'PRD-002',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'sku', 'type', 'sale_price', 'created_at'],
                ],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_list_is_paginated(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => "Product {$i}",
                'sku' => "PRD-{$i}",
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 25);

        $this->assertCount(25, $response->json('data'));
    }

    public function test_can_filter_by_is_physical(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Physical Product',
            'sku' => 'PHY-001',
            'is_physical' => true,
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Digital Product',
            'sku' => 'DIG-001',
            'is_physical' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products?is_physical=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Physical Product');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products?is_physical=0');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Digital Product');
    }

    public function test_can_search_by_name(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Brake Pad Set',
            'sku' => 'BRK-001',
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Air Filter',
            'sku' => 'FLT-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products?search=Brake');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Brake Pad Set');
    }

    public function test_can_search_by_sku(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product One',
            'sku' => 'ABC-123',
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product Two',
            'sku' => 'XYZ-789',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products?search=ABC-123');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'ABC-123');
    }

    public function test_only_shows_products_from_current_tenant(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'My Product',
            'sku' => 'MY-001',
        ]);

        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        Product::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Product',
            'sku' => 'OTH-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My Product');
    }

    public function test_can_get_single_product(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Single Product',
            'sku' => 'SNG-001',
            'sale_price' => '99.99',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Single Product')
            ->assertJsonPath('data.sku', 'SNG-001')
            ->assertJsonPath('data.sale_price', '99.99');
    }

    public function test_returns_404_for_nonexistent_product(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$fakeId}");

        $response->assertNotFound();
    }

    public function test_cannot_view_product_from_another_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company 2',
            'legal_name' => 'Other Company 2 LLC',
            'tax_id' => 'TAX789',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $otherProduct = Product::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Product',
            'sku' => 'OTH-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$otherProduct->id}");

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_list_products(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertUnauthorized();
    }

    public function test_viewer_can_list_products(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TST-001',
        ]);

        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        UserCompanyMembership::create([
            'user_id' => $viewerUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Viewer,
        ]);

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_active_products(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Active Product',
            'sku' => 'ACT-001',
            'is_active' => true,
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Inactive Product',
            'sku' => 'INA-001',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products?is_active=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Product');
    }
}
