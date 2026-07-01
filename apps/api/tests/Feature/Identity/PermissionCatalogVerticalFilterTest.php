<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Read-time vertical gating for the Roles & Permissions catalog endpoints.
 *
 * The seeder ALWAYS creates the full role/permission catalog (technician,
 * operator, vehicles.*, workshop.*, menus.*, etc.) regardless of vertical —
 * 111 other tests depend on that. These tests pin the READ-TIME filter: a
 * tenant must only SEE permission groups + roles whose backing module is
 * enabled for its vertical. Parapharmacy must not see automotive/F&B leakage;
 * a mechanic tenant must still see its automotive groups/roles.
 */
final class PermissionCatalogVerticalFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a tenant on the given vertical, seed the (full) role/permission
     * catalog under that tenant's Spatie team, and return an authenticated
     * admin user for it.
     *
     * @param  array<int, string>  $enabledExtras
     */
    private function makeTenantAdmin(Vertical $vertical, array $enabledExtras = []): User
    {
        $tenant = Tenant::create([
            'name' => 'Tenant '.$vertical->value,
            'slug' => 'tenant-'.$vertical->value,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
            'enabled_extras' => $enabledExtras,
        ]);

        Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Company '.$vertical->value,
            'legal_name' => 'Company '.$vertical->value.' LLC',
            'tax_id' => 'TAX-'.$vertical->value,
            'country_code' => 'TN',
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        // Seed the full catalog under this tenant's team (roles are team-scoped).
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin '.$vertical->value,
            'email' => 'admin-'.$vertical->value.'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $admin->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $admin->id,
            'company_id' => Company::where('tenant_id', $tenant->id)->firstOrFail()->id,
            'role' => 'admin',
        ]);

        return $admin;
    }

    public function test_parapharmacy_permissions_exclude_automotive_and_fnb_groups(): void
    {
        $admin = $this->makeTenantAdmin(Vertical::Parapharmacy, ['Loyalty']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/permissions');
        $response->assertOk();

        $groups = array_keys($response->json('data'));

        // Vertical-gated groups whose module is NOT enabled for parapharmacy.
        // Includes the hyphenated sibling prefixes the catalog actually emits
        // (workshop-bundles/work-orders -> Workshop, modifier-groups -> Menu,
        // composite-items -> CompositeItems), not just the bare tokens.
        $this->assertNotContains('vehicles', $groups);
        $this->assertNotContains('workshop', $groups);
        $this->assertNotContains('workshop-bundles', $groups);
        $this->assertNotContains('work-orders', $groups);
        $this->assertNotContains('menus', $groups);
        $this->assertNotContains('modifier-groups', $groups);
        $this->assertNotContains('composite-items', $groups);
        $this->assertNotContains('scheduling', $groups);

        // Core groups are always shown.
        $this->assertContains('products', $groups);
        $this->assertContains('invoices', $groups);

        // Modules that ARE enabled for parapharmacy (BatchExpiry default + Loyalty extra).
        $this->assertContains('batches', $groups);
        $this->assertContains('loyalty', $groups);
    }

    public function test_parapharmacy_roles_exclude_automotive_roles(): void
    {
        $admin = $this->makeTenantAdmin(Vertical::Parapharmacy, ['Loyalty']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/roles');
        $response->assertOk();

        $roleNames = collect($response->json('data'))->pluck('name')->all();

        $this->assertNotContains('technician', $roleNames);
        $this->assertNotContains('operator', $roleNames);

        // Core roles remain.
        $this->assertContains('admin', $roleNames);
        $this->assertContains('manager', $roleNames);
        $this->assertContains('cashier', $roleNames);
    }

    public function test_mechanic_permissions_include_automotive_groups(): void
    {
        $admin = $this->makeTenantAdmin(Vertical::Mechanic);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/permissions');
        $response->assertOk();

        $groups = array_keys($response->json('data'));

        $this->assertContains('vehicles', $groups);
        $this->assertContains('workshop', $groups);
        // Workshop-backed sibling prefixes are shown too on an automotive vertical.
        $this->assertContains('workshop-bundles', $groups);
        $this->assertContains('work-orders', $groups);
    }

    public function test_mechanic_roles_include_automotive_roles(): void
    {
        $admin = $this->makeTenantAdmin(Vertical::Mechanic);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/roles');
        $response->assertOk();

        $roleNames = collect($response->json('data'))->pluck('name')->all();

        $this->assertContains('technician', $roleNames);
        $this->assertContains('operator', $roleNames);
    }
}
