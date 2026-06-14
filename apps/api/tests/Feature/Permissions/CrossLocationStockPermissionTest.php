<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CrossLocationStockPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_permission_exists(): void
    {
        $perm = Permission::where('name', 'pos.view_cross_location_stock')
            ->where('guard_name', 'sanctum')
            ->first();

        $this->assertNotNull($perm);
    }

    public function test_admin_has_permission(): void
    {
        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->firstOrFail();

        $this->assertTrue($admin->hasPermissionTo('pos.view_cross_location_stock'));
    }

    public function test_manager_has_permission(): void
    {
        $manager = Role::where('name', 'manager')->where('guard_name', 'sanctum')->firstOrFail();

        $this->assertTrue($manager->hasPermissionTo('pos.view_cross_location_stock'));
    }

    public function test_cashier_does_not_have_permission(): void
    {
        $cashier = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->firstOrFail();

        $this->assertFalse($cashier->hasPermissionTo('pos.view_cross_location_stock'));
    }
}
