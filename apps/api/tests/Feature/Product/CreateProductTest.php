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
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CreateProductTest extends TestCase
{
    use AssertsApiValidation;
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

    public function test_name_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'sku' => 'SKU-001',
            ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_sku_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
            ]);

        $this->assertApiValidationErrors($response, ['sku']);
    }

    public function test_sku_must_be_unique_within_tenant(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'First Product',
                'sku' => 'SKU-001',
            ])
            ->assertCreated();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Second Product',
                'sku' => 'SKU-001',
            ]);

        $this->assertApiValidationErrors($response, ['sku']);
    }

    public function test_type_is_nullable(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', null);
    }

    public function test_type_must_be_valid_when_provided(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
                'type' => 'invalid',
            ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_can_create_product_with_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Part Product',
                'sku' => 'PART-001',
                'type' => 'part',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'part');
    }

    public function test_can_set_type_to_null_explicitly(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Generic Product',
                'sku' => 'GEN-001',
                'type' => null,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', null);
    }

    public function test_can_create_product_with_is_physical_true(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Physical Product',
                'sku' => 'PHY-001',
                'is_physical' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_physical', true);
    }

    public function test_can_create_product_with_is_physical_false(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Digital Product',
                'sku' => 'DIG-001',
                'is_physical' => false,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_physical', false);
    }

    public function test_sale_price_must_be_numeric(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
                'sale_price' => 'not-a-number',
            ]);

        $this->assertApiValidationErrors($response, ['sale_price']);
    }

    public function test_purchase_price_must_be_numeric(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
                'purchase_price' => 'not-a-number',
            ]);

        $this->assertApiValidationErrors($response, ['purchase_price']);
    }

    public function test_successful_creation_returns_201(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Brake Pad Set',
                'sku' => 'BRK-PAD-001',
                'description' => 'Front brake pad set for various models',
                'sale_price' => '49.99',
                'purchase_price' => '25.00',
                'tax_rate' => '20.00',
                'unit' => 'set',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Brake Pad Set')
            ->assertJsonPath('data.sku', 'BRK-PAD-001')
            ->assertJsonPath('data.sale_price', '49.99')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'sku',
                    'type',
                    'description',
                    'sale_price',
                    'purchase_price',
                    'tax_rate',
                    'unit',
                    'created_at',
                ],
                'meta',
            ]);

        $this->assertDatabaseHas('products', [
            'name' => 'Brake Pad Set',
            'sku' => 'BRK-PAD-001',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_can_store_oem_numbers(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Air Filter',
                'sku' => 'FLT-001',
                'oem_numbers' => ['1234567890', 'ABC123DEF'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.oem_numbers', ['1234567890', 'ABC123DEF']);
    }

    public function test_can_store_cross_references(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Oil Filter',
                'sku' => 'FLT-002',
                'cross_references' => [
                    ['brand' => 'Bosch', 'reference' => 'F026407022'],
                    ['brand' => 'Mann', 'reference' => 'W712/80'],
                ],
            ]);

        $response->assertCreated();
        $this->assertCount(2, $response->json('data.cross_references'));
    }

    public function test_unauthenticated_user_cannot_create_product(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'Test Product',
            'sku' => 'SKU-001',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_create_product(): void
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
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
            ]);

        $response->assertForbidden();
    }
}
