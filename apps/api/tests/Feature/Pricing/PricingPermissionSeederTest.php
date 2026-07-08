<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: the seeded admin role must be able to list price lists.
 *
 * Pre-fix, `pricing.view` / `pricing.manage` were absent from the
 * RolesAndPermissionsSeeder permission list, so admin's
 * `syncPermissions(Permission::all())` never included them and the
 * `can:pricing.view` route middleware 403'd the demo owner — the page
 * rendered "Échec du chargement des données". This test asserts the fix at
 * the seeder layer WITHOUT manually creating the permission.
 */
final class PricingPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_pricing_permissions(): void
    {
        $tenant = $this->makeTenant('seed-perm');
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertNotNull(
            Permission::where('name', 'pricing.view')->where('guard_name', 'sanctum')->first(),
            'Seeder must create the pricing.view permission.',
        );
        $this->assertNotNull(
            Permission::where('name', 'pricing.manage')->where('guard_name', 'sanctum')->first(),
            'Seeder must create the pricing.manage permission.',
        );
    }

    public function test_manager_gets_cost_and_floor_permissions_without_new_override_permission(): void
    {
        $tenant = $this->makeTenant('manager-cost');
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = Role::where('name', 'manager')->firstOrFail();
        self::assertTrue($manager->hasPermissionTo('pricing.view_cost_prices', 'sanctum'));
        self::assertTrue($manager->hasPermissionTo('pricing.sell_below_minimum_margin', 'sanctum'));
        self::assertTrue($manager->hasPermissionTo('pricing.sell_below_cost', 'sanctum'));
        self::assertNull(Permission::where('name', 'pricing.override_discount_floor')->first());
    }

    public function test_legacy_permission_catalog_also_registers_existing_margin_permissions(): void
    {
        $tenant = $this->makeTenant('legacy-catalog');
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $this->seed(PermissionSeeder::class);

        self::assertNotNull(Permission::where('name', 'pricing.view_cost_prices')->first());
        self::assertNotNull(Permission::where('name', 'pricing.sell_below_minimum_margin')->first());
        self::assertNotNull(Permission::where('name', 'pricing.sell_below_cost')->first());
        self::assertNull(Permission::where('name', 'pricing.override_discount_floor')->first());
    }

    public function test_permission_cache_reset_makes_manager_cost_permission_visible(): void
    {
        $tenant = $this->makeTenant('cache-reset');
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = Role::where('name', 'manager')->firstOrFail();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        self::assertTrue($manager->fresh()->hasPermissionTo('pricing.view_cost_prices', 'sanctum'));
    }

    public function test_seeded_admin_role_can_list_price_lists(): void
    {
        $tenant = $this->makeTenant('admin-list');
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Co',
            'legal_name' => 'Demo Co LLC',
            'tax_id' => 'TAX-DEMO-PRICING',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $owner = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
            'email' => 'owner-pricing-seed@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $owner->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->getJson('/api/v1/price-lists')
            ->assertStatus(200);
    }

    private function makeTenant(string $suffix): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant '.$suffix,
            'slug' => 'tenant-'.$suffix.'-pricing-perm',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }
}
