<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ModuleName;
use App\Enums\Vertical;
use App\Models\SuperAdmin;
use App\Models\VerticalConfig;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use App\Services\VerticalConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerticalConfigManagementTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdmin $superAdmin;

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
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/verticals')
            ->assertUnauthorized();
    }

    public function test_index_returns_all_verticals_with_available_modules(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/verticals');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'vertical',
                        'label',
                        'product',
                        'default_modules',
                        'compatible_extras',
                        'is_overridden',
                    ],
                ],
                'available_modules',
            ])
            ->assertJsonCount(count(Vertical::cases()), 'data');

        $this->assertSame(ModuleName::values(), $response->json('available_modules'));

        // No vertical_configs rows yet — nothing is overridden, and the
        // effective values are the config/verticals.php values.
        foreach ($response->json('data') as $item) {
            $this->assertFalse($item['is_overridden']);
        }

        $retail = $this->findVerticalItem($response->json('data'), Vertical::Retail->value);
        $this->assertSame(config('verticals.retail.default_modules'), $retail['default_modules']);
        $this->assertSame(config('verticals.retail.compatible_extras'), $retail['compatible_extras']);
        $this->assertSame('izipos', $retail['product']);
    }

    public function test_update_requires_authentication(): void
    {
        $this->putJson('/api/v1/admin/verticals/retail', [
            'default_modules' => ['Identity'],
            'compatible_extras' => [],
        ])->assertUnauthorized();
    }

    public function test_update_rejects_unknown_vertical(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->putJson('/api/v1/admin/verticals/not-a-vertical', [
                'default_modules' => ['Identity'],
                'compatible_extras' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['error', 'valid_verticals']);

        $this->assertSame(
            array_map(static fn (Vertical $v): string => $v->value, Vertical::cases()),
            $response->json('valid_verticals')
        );
    }

    public function test_update_rejects_invalid_module_name(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->putJson('/api/v1/admin/verticals/retail', [
                'default_modules' => ['Identity', 'NotARealModule'],
                'compatible_extras' => ['Loyalty', 'AlsoFake'],
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['error', 'valid_modules']);

        $this->assertStringContainsString('NotARealModule', (string) $response->json('error'));
        $this->assertStringContainsString('AlsoFake', (string) $response->json('error'));
        $this->assertSame(ModuleName::values(), $response->json('valid_modules'));

        // The invalid write must never reach the DB.
        $this->assertDatabaseMissing('vertical_configs', [
            'vertical' => Vertical::Retail->value,
        ]);
    }

    public function test_update_requires_both_fields_present(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->putJson('/api/v1/admin/verticals/retail', [
                'default_modules' => ['Identity'],
                // compatible_extras missing entirely
            ])
            ->assertUnprocessable();
    }

    public function test_update_accepts_valid_modules_and_index_reflects_override(): void
    {
        $newDefaults = ['Identity', 'Tenant', 'Catalog', 'Sales', 'Treasury'];
        $newExtras = ['Loyalty'];

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->putJson('/api/v1/admin/verticals/retail', [
                'default_modules' => $newDefaults,
                'compatible_extras' => $newExtras,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.vertical', Vertical::Retail->value)
            ->assertJsonPath('data.default_modules', $newDefaults)
            ->assertJsonPath('data.compatible_extras', $newExtras)
            ->assertJsonPath('data.is_overridden', true);

        $this->assertNotNull(VerticalConfig::query()->find(Vertical::Retail->value));

        $index = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson('/api/v1/admin/verticals');

        $retail = $this->findVerticalItem($index->json('data'), Vertical::Retail->value);
        $this->assertTrue($retail['is_overridden']);
        $this->assertSame($newDefaults, $retail['default_modules']);
        $this->assertSame($newExtras, $retail['compatible_extras']);
    }

    public function test_update_invalidates_cached_tenant_config(): void
    {
        $tenant = Tenant::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Retail Tenant',
            'slug' => 'retail-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
            'enabled_extras' => [],
        ]);

        // Prime the 24h tenant-config cache the way the tenant app does.
        $configService = app(CompanyConfigService::class);
        $before = $configService->getConfigForTenant($tenant);
        $this->assertNotContains('Menu', $before->allEnabledModules);

        $newDefaults = array_merge(
            (array) config('verticals.retail.default_modules'),
            ['Menu']
        );

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->putJson('/api/v1/admin/verticals/retail', [
                'default_modules' => $newDefaults,
                'compatible_extras' => (array) config('verticals.retail.compatible_extras'),
            ])
            ->assertOk();

        // A module list changed by a super admin must be visible to the
        // tenant immediately — not after the 24h cache TTL expires.
        $after = $configService->getConfigForTenant($tenant->refresh());
        $this->assertContains('Menu', $after->allEnabledModules);
        $this->assertSame($newDefaults, $after->defaultModules);
    }

    public function test_update_writes_audit_log(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->putJson('/api/v1/admin/verticals/coffee_shop', [
                'default_modules' => ['Identity', 'Tenant', 'Catalog', 'Sales'],
                'compatible_extras' => ['Tables'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'super_admin_id' => $this->superAdmin->id,
            'tenant_id' => null,
            'action' => 'update_vertical_config',
            'entity_type' => 'vertical_config',
            'entity_id' => Vertical::CoffeeShop->value,
        ]);
    }

    /**
     * Find a vertical item in the index/update response payload.
     *
     * @return array<string, mixed>
     */
    private function findVerticalItem(mixed $items, string $vertical): array
    {
        $this->assertIsArray($items);

        foreach ($items as $item) {
            $this->assertIsArray($item);

            if (($item['vertical'] ?? null) === $vertical) {
                /** @var array<string, mixed> $item */
                return $item;
            }
        }

        $this->fail("Vertical '{$vertical}' not found in response data");
    }

    public function test_vertical_config_model_save_invalidates_override_cache(): void
    {
        $service = new VerticalConfigService;

        // Prime the override cache (no row → config values, cached).
        $this->assertSame(
            config('verticals.fashion.default_modules'),
            $service->getDefaultModules(Vertical::Fashion)
        );

        // A direct model write (future writers, seeders, tinker) must
        // self-invalidate — not depend on the controller remembering to.
        VerticalConfig::query()->create([
            'vertical' => Vertical::Fashion->value,
            'default_modules' => ['Identity', 'Tenant', 'Catalog'],
            'compatible_extras' => ['Loyalty'],
        ]);

        $this->assertSame(
            ['Identity', 'Tenant', 'Catalog'],
            $service->getDefaultModules(Vertical::Fashion)
        );

        // Deleting the row must restore config-file fallback immediately.
        VerticalConfig::query()->find(Vertical::Fashion->value)?->delete();

        $this->assertSame(
            config('verticals.fashion.default_modules'),
            $service->getDefaultModules(Vertical::Fashion)
        );
    }
}
