<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * W-X (2026-08-05): `taxation.tax_configurations.manage` gates all tax-config
 * writes (Taxation/routes.php:23-31 — store/update/destroy/reorder) but was
 * never created by the per-tenant provisioning seeder
 * (RolesAndPermissionsSeeder, run by TenantInitializationService), only by
 * the central-bootstrap PermissionSeeder. Every real tenant therefore had
 * ZERO users — not even admin — able to manage tax configurations.
 *
 * docs/superpowers/tickets/2026-08-05-wx-tax-config-unmanageable.md
 */
final class RolesAndPermissionsTaxConfigManageGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_is_seeded_per_tenant(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue(
            Permission::where('name', 'taxation.tax_configurations.manage')
                ->where('guard_name', 'sanctum')
                ->exists(),
            'taxation.tax_configurations.manage must be created by the per-tenant seeder',
        );
    }

    public function test_admin_and_accountant_get_the_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = Role::findByName('admin', 'sanctum');
        $accountant = Role::findByName('accountant', 'sanctum');

        $this->assertTrue($admin->hasPermissionTo('taxation.tax_configurations.manage', 'sanctum'));
        $this->assertTrue($accountant->hasPermissionTo('taxation.tax_configurations.manage', 'sanctum'));
    }

    public function test_viewer_manager_and_cashier_do_not_get_the_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewer = Role::findByName('viewer', 'sanctum');
        $manager = Role::findByName('manager', 'sanctum');
        $cashier = Role::findByName('cashier', 'sanctum');

        $this->assertFalse($viewer->hasPermissionTo('taxation.tax_configurations.manage', 'sanctum'));
        $this->assertFalse($manager->hasPermissionTo('taxation.tax_configurations.manage', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('taxation.tax_configurations.manage', 'sanctum'));
    }
}
