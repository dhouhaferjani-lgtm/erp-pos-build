<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class P2pEntryPointPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $permissions = [
        'goods-receipt.create-standalone',
        'supplier-invoices.create-pending',
        'supplier-invoices.link-receipts',
        'supplier-invoices.approve-invoice-first',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_entry_point_permissions_exist(): void
    {
        foreach ($this->permissions as $permissionName) {
            $this->assertNotNull(
                Permission::where('name', $permissionName)
                    ->where('guard_name', 'sanctum')
                    ->first(),
                "Missing permission [{$permissionName}].",
            );
        }
    }

    public function test_manager_has_all_entry_point_permissions(): void
    {
        $manager = $this->role('manager');

        foreach ($this->permissions as $permissionName) {
            $this->assertTrue($manager->hasPermissionTo($permissionName));
        }
    }

    public function test_accountant_has_supplier_invoice_entry_point_permissions_only(): void
    {
        $accountant = $this->role('accountant');

        $this->assertFalse($accountant->hasPermissionTo('goods-receipt.create-standalone'));
        $this->assertTrue($accountant->hasPermissionTo('supplier-invoices.create-pending'));
        $this->assertTrue($accountant->hasPermissionTo('supplier-invoices.link-receipts'));
        $this->assertTrue($accountant->hasPermissionTo('supplier-invoices.approve-invoice-first'));
    }

    public function test_viewer_cashier_and_operator_do_not_get_entry_point_permissions(): void
    {
        foreach (['viewer', 'cashier', 'operator'] as $roleName) {
            $role = $this->role($roleName);

            foreach ($this->permissions as $permissionName) {
                $this->assertFalse($role->hasPermissionTo($permissionName));
            }
        }
    }

    private function role(string $name): Role
    {
        return Role::where('name', $name)
            ->where('guard_name', 'sanctum')
            ->firstOrFail();
    }
}
