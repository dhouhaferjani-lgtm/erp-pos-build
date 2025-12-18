<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
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
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
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
            'role' => \App\Modules\Company\Domain\Enums\MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_name_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'sku' => 'SKU-001',
                'type' => 'part',
            ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_sku_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'type' => 'part',
            ]);

        $this->assertApiValidationErrors($response, ['sku']);
    }

    public function test_sku_must_be_unique_within_tenant(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'First Product',
                'sku' => 'SKU-001',
                'type' => 'part',
            ])
            ->assertCreated();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Second Product',
                'sku' => 'SKU-001',
                'type' => 'part',
            ]);

        $this->assertApiValidationErrors($response, ['sku']);
    }

    public function test_type_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
            ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_type_must_be_valid(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
                'type' => 'invalid',
            ]);

        $this->assertApiValidationErrors($response, ['type']);
    }

    public function test_sale_price_must_be_numeric(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
                'type' => 'part',
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
                'type' => 'part',
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
                'type' => 'part',
                'description' => 'Front brake pad set for various models',
                'sale_price' => '49.99',
                'purchase_price' => '25.00',
                'tax_rate' => '20.00',
                'unit' => 'set',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Brake Pad Set')
            ->assertJsonPath('data.sku', 'BRK-PAD-001')
            ->assertJsonPath('data.type', 'part')
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

    public function test_can_create_part_type(): void
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

    public function test_can_create_service_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Labor Service',
                'sku' => 'SVC-001',
                'type' => 'service',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'service');
    }

    public function test_can_create_consumable_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Motor Oil 5W-30',
                'sku' => 'CON-001',
                'type' => 'consumable',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'consumable');
    }

    public function test_can_store_oem_numbers(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Air Filter',
                'sku' => 'FLT-001',
                'type' => 'part',
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
                'type' => 'part',
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
            'type' => 'part',
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
            'role' => \App\Modules\Company\Domain\Enums\MembershipRole::Viewer,
        ]);

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'SKU-001',
                'type' => 'part',
            ]);

        $response->assertForbidden();
    }
}
