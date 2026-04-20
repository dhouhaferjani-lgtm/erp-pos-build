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
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeleteProductTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Product $product;

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

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product To Delete',
            'sku' => 'DEL-001',
            'type' => ProductType::Part,
        ]);
    }

    public function test_can_delete_product(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$this->product->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('products', [
            'id' => $this->product->id,
        ]);
    }

    public function test_returns_404_for_nonexistent_product(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$fakeId}");

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_delete_product(): void
    {
        $response = $this->deleteJson("/api/v1/products/{$this->product->id}");

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_delete_product(): void
    {
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
            ->deleteJson("/api/v1/products/{$this->product->id}");

        $response->assertForbidden();
    }

    public function test_cannot_delete_product_from_another_tenant(): void
    {
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

        $otherProduct = Product::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Product',
            'sku' => 'OTH-001',
            'type' => ProductType::Part,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$otherProduct->id}");

        $response->assertNotFound();

        $this->assertDatabaseHas('products', [
            'id' => $otherProduct->id,
            'deleted_at' => null,
        ]);
    }

    public function test_deleted_product_not_shown_in_list(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/products/{$this->product->id}")
            ->assertNoContent();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
