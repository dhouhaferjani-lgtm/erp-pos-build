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
use Illuminate\Support\Facades\DB;
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

    /**
     * Seeder must create AND grant the target-margin override permission that
     * MarginService's LEVEL_YELLOW branch checks (`$user->can(
     * 'pricing.sell_below_target_margin')`, MarginService ~L387). Pre-fix the
     * permission was never seeded, so it resolved false for everyone — including
     * admin — and a yellow-margin check wrongly returned allowed:false.
     */
    public function test_seeder_creates_and_grants_sell_below_target_margin_permission(): void
    {
        $tenant = $this->makeTenant('target-margin');
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // (1) The permission exists in the seeded catalog.
        self::assertNotNull(
            Permission::where('name', 'pricing.sell_below_target_margin')
                ->where('guard_name', 'sanctum')->first(),
            'Seeder must create pricing.sell_below_target_margin.',
        );

        // (2) The manager role holds it directly (granted alongside
        //     pricing.sell_below_minimum_margin).
        $managerRole = Role::where('name', 'manager')->firstOrFail();
        self::assertTrue($managerRole->hasPermissionTo('pricing.sell_below_target_margin', 'sanctum'));

        // (3) manager + admin users resolve can()=true; a roleless user is denied.
        $adminUser = $this->makeUser($tenant, 'admin-target@example.com', 'admin');
        $managerUser = $this->makeUser($tenant, 'manager-target@example.com', 'manager');
        $rolelessUser = $this->makeUser($tenant, 'roleless-target@example.com', null);

        self::assertTrue($adminUser->can('pricing.sell_below_target_margin'), 'admin (all perms) must be allowed.');
        self::assertTrue($managerUser->can('pricing.sell_below_target_margin'), 'manager must be allowed.');
        self::assertFalse($rolelessUser->can('pricing.sell_below_target_margin'), 'roleless user must be denied.');
    }

    /**
     * Task 2 alignment: the (currently inert) legacy PermissionSeeder's
     * manager-equivalent role ("Sales Manager") must also receive the pricing
     * cost/margin controls, so it can't silently strip them if it were ever
     * promoted to the provisioning path.
     */
    public function test_legacy_seeder_sales_manager_gets_pricing_cost_permissions(): void
    {
        $tenant = $this->makeTenant('legacy-mgr-cost');
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(PermissionSeeder::class);

        $salesManager = Role::where('name', 'Sales Manager')->where('guard_name', 'sanctum')->firstOrFail();
        self::assertTrue($salesManager->hasPermissionTo('pricing.view_cost_prices', 'sanctum'));
        self::assertTrue($salesManager->hasPermissionTo('pricing.sell_below_minimum_margin', 'sanctum'));
        self::assertTrue($salesManager->hasPermissionTo('pricing.sell_below_target_margin', 'sanctum'));
    }

    /**
     * Exercises the tenant-blind permission-cache contract end to end: a grant
     * applied while the registrar cache is warm is INVISIBLE until
     * forgetCachedPermissions() is called. Asserts BOTH the before (stale ->
     * denied) and after (reset -> allowed) states so the test name is honest.
     *
     * We probe via a USER (not a Role): a user's permission check resolves the
     * permission's role pivot from the registrar's *cached* permission
     * collection (getPermissions() eager-loads roles) — exactly the tenant-blind
     * surface. A raw pivot insert bypasses Spatie's model events so the cache is
     * left warm-but-stale.
     */
    public function test_permission_cache_reset_makes_manager_cost_permission_visible(): void
    {
        $tenant = $this->makeTenant('cache-reset');
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = $this->makeUser($tenant, 'mgr-cache-reset@example.com', 'manager');

        // A brand-new permission that no role holds yet.
        $probe = Permission::firstOrCreate(['name' => 'pricing.cache_probe', 'guard_name' => 'sanctum']);

        // Warm the permission cache while the manager role does NOT hold the probe.
        self::assertFalse($manager->can('pricing.cache_probe'), 'Precondition: probe not yet granted.');

        // Attach the permission to the manager role via a RAW pivot insert so
        // Spatie's cache-forget model events never fire — the cache stays stale.
        $managerRole = Role::where('name', 'manager')->where('guard_name', 'sanctum')->firstOrFail();
        DB::table('role_has_permissions')->insert([
            'permission_id' => $probe->id,
            'role_id' => $managerRole->id,
        ]);

        // BEFORE reset: the warm-but-stale cache still denies the grant.
        self::assertFalse(
            $manager->fresh()->can('pricing.cache_probe'),
            'Warm-but-stale permission cache must NOT yet see the raw pivot grant.',
        );

        // Bust the cache — the required step of the tenant-blind-cache contract.
        $registrar->forgetCachedPermissions();

        // AFTER reset: the grant is now visible.
        self::assertTrue(
            $manager->fresh()->can('pricing.cache_probe'),
            'After forgetCachedPermissions the manager must see the new grant.',
        );
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

    private function makeUser(Tenant $tenant, string $email, ?string $role): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'U '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
