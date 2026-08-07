<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Route gating (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md
 * #3, §153-174): `taxation.withholding_rules.manage` gates the withholding
 * RULES group (Taxation/routes.php:38-45 — index/show/store/update/
 * deactivate/destroy) and is what `CreateWithholdingRuleRequest`/
 * `UpdateWithholdingRuleRequest`'s `authorize()` already reference — but,
 * exactly like `taxation.tax_configurations.manage` before it
 * (docs/superpowers/tickets/2026-08-05-wx-tax-config-unmanageable.md), the
 * permission was only ever created by the DEAD central-bootstrap
 * `PermissionSeeder` (never called by `DatabaseSeeder`), never by the
 * per-tenant provisioning seeder `RolesAndPermissionsSeeder` (run by
 * `TenantInitializationService`). Every real tenant therefore had ZERO
 * users — not even admin — able to satisfy this permission, and the two
 * lifecycle actions with NO authorization check at all (`deactivate`,
 * `destroy`) were reachable by any authenticated tenant user.
 */
final class RolesAndPermissionsWithholdingRulesManageGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_is_seeded_per_tenant(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue(
            Permission::where('name', 'taxation.withholding_rules.manage')
                ->where('guard_name', 'sanctum')
                ->exists(),
            'taxation.withholding_rules.manage must be created by the per-tenant seeder',
        );
    }

    public function test_admin_and_accountant_get_the_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = Role::findByName('admin', 'sanctum');
        $accountant = Role::findByName('accountant', 'sanctum');

        $this->assertTrue($admin->hasPermissionTo('taxation.withholding_rules.manage', 'sanctum'));
        $this->assertTrue($accountant->hasPermissionTo('taxation.withholding_rules.manage', 'sanctum'));
    }

    public function test_viewer_manager_and_cashier_do_not_get_the_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewer = Role::findByName('viewer', 'sanctum');
        $manager = Role::findByName('manager', 'sanctum');
        $cashier = Role::findByName('cashier', 'sanctum');

        $this->assertFalse($viewer->hasPermissionTo('taxation.withholding_rules.manage', 'sanctum'));
        $this->assertFalse($manager->hasPermissionTo('taxation.withholding_rules.manage', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('taxation.withholding_rules.manage', 'sanctum'));
    }
}
