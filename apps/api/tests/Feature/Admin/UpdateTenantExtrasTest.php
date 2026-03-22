<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Vertical;
use App\Models\SuperAdmin;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateTenantExtrasTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdmin $superAdmin;

    private Tenant $tenantWithVertical;

    private Tenant $tenantWithoutVertical;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // CoffeeShop vertical: compatible extras are ['Tables', 'Loyalty']
        $this->tenantWithVertical = Tenant::create([
            'id' => Str::uuid()->toString(),
            'name' => 'CoffeeShop Tenant',
            'slug' => 'coffeeshop-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => [],
        ]);

        $this->tenantWithoutVertical = Tenant::create([
            'id' => Str::uuid()->toString(),
            'name' => 'No Vertical Tenant',
            'slug' => 'no-vertical-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_update_extras_with_valid_modules(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/tenants/{$this->tenantWithVertical->id}/update-extras", [
                'enabled_extras' => ['Tables'],
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'vertical',
                    'enabled_extras',
                ],
            ])
            ->assertJsonPath('data.enabled_extras', ['Tables']);

        $this->tenantWithVertical->refresh();
        $this->assertEquals(['Tables'], $this->tenantWithVertical->enabled_extras);
    }

    public function test_update_extras_rejects_invalid_module(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/tenants/{$this->tenantWithVertical->id}/update-extras", [
                'enabled_extras' => ['NonExistentModule'],
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'error',
                'valid_extras',
            ]);

        // Verify the extras were NOT updated
        $this->tenantWithVertical->refresh();
        $this->assertNotEquals(['NonExistentModule'], $this->tenantWithVertical->enabled_extras);
    }

    public function test_update_extras_rejects_incompatible_module(): void
    {
        // CoffeeShop only supports ['Tables', 'Loyalty']
        // 'Inventory' is not a compatible extra for CoffeeShop (it's a default module)
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/tenants/{$this->tenantWithVertical->id}/update-extras", [
                'enabled_extras' => ['Ecommerce'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', fn (string $msg) => str_contains($msg, 'coffee_shop'));
    }

    public function test_update_extras_returns_422_when_tenant_has_no_vertical(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/tenants/{$this->tenantWithoutVertical->id}/update-extras", [
                'enabled_extras' => ['Loyalty'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Tenant has no vertical configured');
    }

    public function test_update_extras_requires_authentication(): void
    {
        $response = $this->postJson("/api/v1/admin/tenants/{$this->tenantWithVertical->id}/update-extras", [
            'enabled_extras' => ['Tables'],
        ]);

        $response->assertUnauthorized();
    }

    public function test_update_extras_validates_request(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/tenants/{$this->tenantWithVertical->id}/update-extras", [
                // missing enabled_extras entirely
            ]);

        $response->assertUnprocessable();
    }

    public function test_update_extras_allows_empty_array_to_disable_all(): void
    {
        // First enable some extras
        $this->tenantWithVertical->update(['enabled_extras' => ['Tables', 'Loyalty']]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("/api/v1/admin/tenants/{$this->tenantWithVertical->id}/update-extras", [
                'enabled_extras' => [],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.enabled_extras', []);

        $this->tenantWithVertical->refresh();
        $this->assertEquals([], $this->tenantWithVertical->enabled_extras);
    }

    public function test_show_tenant_includes_compatible_extras(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("/api/v1/admin/tenants/{$this->tenantWithVertical->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'tenant',
                    'stats',
                    'compatible_extras',
                ],
            ]);

        $compatibleExtras = $response->json('data.compatible_extras');
        $this->assertIsArray($compatibleExtras);
        $this->assertContains('Tables', $compatibleExtras);
        $this->assertContains('Loyalty', $compatibleExtras);
        $this->assertContains('Inventory', $compatibleExtras);
    }
}
