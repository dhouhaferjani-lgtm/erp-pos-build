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
use Tests\Traits\AssertsApiValidation;

class UpdateProductTest extends TestCase
{
    use AssertsApiValidation;
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
            'name' => 'Original Product',
            'sku' => 'ORG-001',
            'sale_price' => '50.00',
        ]);
    }

    public function test_can_update_product_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'name' => 'Updated Product Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Product Name');

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'name' => 'Updated Product Name',
        ]);
    }

    public function test_can_update_product_price(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'sale_price' => '75.99',
            ]);

        $response->assertOk();
        // sale_price is numeric(15,3); PostgreSQL returns "75.990", SQLite "75.99".
        $this->assertEqualsWithDelta(75.99, (float) $response->json('data.sale_price'), 0.001);
    }

    public function test_can_update_type_to_valid_value(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'type' => 'part',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.type', 'part');
    }

    public function test_can_set_type_to_null(): void
    {
        // First set a type
        $this->product->update(['type' => 'part']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'type' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.type', null);
    }

    public function test_can_update_is_physical(): void
    {
        $this->assertTrue($this->product->is_physical);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'is_physical' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.is_physical', false);

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'is_physical' => false,
        ]);
    }

    public function test_sku_uniqueness_on_update(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Product',
            'sku' => 'OTH-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'sku' => 'OTH-001',
            ]);

        $this->assertApiValidationErrors($response, ['sku']);
    }

    public function test_can_update_own_sku_to_same_value(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'sku' => 'ORG-001',
                'name' => 'Updated Name',
            ]);

        $response->assertOk();
    }

    public function test_can_update_oem_numbers(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'oem_numbers' => ['NEW-OEM-123', 'NEW-OEM-456'],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.oem_numbers', ['NEW-OEM-123', 'NEW-OEM-456']);
    }

    public function test_can_deactivate_product(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'is_active' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_returns_404_for_nonexistent_product(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$fakeId}", [
                'name' => 'Updated Name',
            ]);

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_update_product(): void
    {
        $response = $this->patchJson("/api/v1/products/{$this->product->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_update_product(): void
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
            ->patchJson("/api/v1/products/{$this->product->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_update_product_from_another_tenant(): void
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
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$otherProduct->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertNotFound();
    }
}
