<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CheckMarginTest extends TestCase
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
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
            'default_target_margin' => '30.00',
            'default_minimum_margin' => '15.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(PermissionSeeder::class);

        // Create sanctum permissions from web permissions
        $webPermissions = \Spatie\Permission\Models\Permission::where('guard_name', 'web')->get();
        foreach ($webPermissions as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate([
                'name' => $permission->name,
                'guard_name' => 'sanctum',
            ]);
        }

        // Create Administrator role for sanctum guard with all permissions
        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Administrator',
            'guard_name' => 'sanctum',
        ]);
        $adminRole->syncPermissions(\Spatie\Permission\Models\Permission::where('guard_name', 'sanctum')->pluck('name'));

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('Administrator'); // Assign admin role for testing

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(\App\Modules\Company\Services\CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'type' => ProductType::Part,
            'cost_price' => '100.00',
            'list_price' => '150.00',
        ]);
    }

    public function test_can_check_margin_for_good_price(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => 140.00,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'cost_price',
                'sell_price',
                'margin_level',
                'can_sell',
                'suggested_price',
                'margins',
            ],
        ]);

        $marginLevel = $response->json('data.margin_level');
        $this->assertEquals(MarginService::LEVEL_GREEN, $marginLevel['level']);
    }

    public function test_margin_level_yellow_for_below_target(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => 120.00, // 20% margin (below 30% target)
            ]);

        $response->assertStatus(200);
        $marginLevel = $response->json('data.margin_level');
        $this->assertEquals(MarginService::LEVEL_YELLOW, $marginLevel['level']);
    }

    public function test_margin_level_orange_for_below_minimum(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => 110.00, // 10% margin (below 15% minimum)
            ]);

        $response->assertStatus(200);
        $marginLevel = $response->json('data.margin_level');
        $this->assertEquals(MarginService::LEVEL_ORANGE, $marginLevel['level']);
    }

    public function test_margin_level_red_for_below_cost(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => 90.00, // Below cost
            ]);

        $response->assertStatus(200);
        $marginLevel = $response->json('data.margin_level');
        $this->assertEquals(MarginService::LEVEL_RED, $marginLevel['level']);
    }

    public function test_suggests_price_based_on_target_margin(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => 120.00,
            ]);

        $response->assertStatus(200);
        $suggestedPrice = (float) $response->json('data.suggested_price');

        // Target margin is 30%, so suggested = cost * (1 + margin%) = 100 * 1.30 = 130
        $this->assertEqualsWithDelta(130.00, $suggestedPrice, 0.01);
    }

    public function test_can_sell_returns_true_for_admin_user(): void
    {
        // Enable below cost sales for this company
        $this->company->update(['allow_below_cost_sales' => true]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => 90.00, // Below cost
            ]);

        $response->assertStatus(200);
        $canSell = $response->json('data.can_sell');

        // Admin with permissions and company allowing below-cost sales should be allowed
        $this->assertIsArray($canSell);
        $this->assertTrue($canSell['allowed']);
    }

    public function test_validates_product_id_exists(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => 'non-existent-uuid',
                'sell_price' => 100.00,
            ]);

        $response->assertStatus(422);
    }

    public function test_validates_sell_price_is_positive(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => -50.00,
            ]);

        $this->assertApiValidationErrors($response, ['sell_price']);
    }

    public function test_returns_effective_margins(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => 130.00,
            ]);

        $response->assertStatus(200);
        $margins = $response->json('data.margins');

        $this->assertArrayHasKey('target_margin', $margins);
        $this->assertArrayHasKey('minimum_margin', $margins);
        $this->assertArrayHasKey('source', $margins);

        // Should inherit from company defaults
        $this->assertEquals('30.00', $margins['target_margin']);
        $this->assertEquals('15.00', $margins['minimum_margin']);
        $this->assertEquals('company', $margins['source']);
    }

    public function test_uses_product_margin_override(): void
    {
        $this->product->update([
            'target_margin_override' => '40.00',
            'minimum_margin_override' => '20.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->product->id,
                'sell_price' => 150.00,
            ]);

        $response->assertStatus(200);
        $margins = $response->json('data.margins');

        $this->assertEquals('40.00', $margins['target_margin']);
        $this->assertEquals('20.00', $margins['minimum_margin']);
        $this->assertEquals('product', $margins['source']);
    }
}
