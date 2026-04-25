<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CashCountPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_pos_close_shift_with_variance_permission_exists(): void
    {
        $perm = Permission::where('name', 'pos.close_shift_with_variance')
            ->where('guard_name', 'sanctum')
            ->first();

        $this->assertNotNull($perm);
    }

    public function test_pos_configure_cash_count_permission_exists(): void
    {
        $perm = Permission::where('name', 'pos.configure_cash_count')
            ->where('guard_name', 'sanctum')
            ->first();

        $this->assertNotNull($perm);
    }

    public function test_admin_has_both_permissions(): void
    {
        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->firstOrFail();

        $this->assertTrue($admin->hasPermissionTo('pos.close_shift_with_variance'));
        $this->assertTrue($admin->hasPermissionTo('pos.configure_cash_count'));
    }

    public function test_manager_has_both_permissions(): void
    {
        $manager = Role::where('name', 'manager')->where('guard_name', 'sanctum')->firstOrFail();

        $this->assertTrue($manager->hasPermissionTo('pos.close_shift_with_variance'));
        $this->assertTrue($manager->hasPermissionTo('pos.configure_cash_count'));
    }

    public function test_cashier_does_not_have_either_permission(): void
    {
        $cashier = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->firstOrFail();

        $this->assertFalse($cashier->hasPermissionTo('pos.close_shift_with_variance'));
        $this->assertFalse($cashier->hasPermissionTo('pos.configure_cash_count'));
    }
}
