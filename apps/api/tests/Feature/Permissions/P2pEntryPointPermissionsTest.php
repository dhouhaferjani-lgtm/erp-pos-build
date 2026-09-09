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

    /**
     * The P2P entry-point permission matrix.
     *
     * Gate r1 finding 7 added `supplier-invoices.manage` (the dedicated gate on
     * POST /supplier-invoices, .../match and .../post, F-W2-14) and
     * `payments.pay-supplier` (the AP branch of POST /payments, F-W2-14
     * residual (a)) so the deny matrix for viewer/cashier/operator lives HERE,
     * beside the rest of the procure-to-pay catalogue, and not only inside the
     * feature test of one endpoint.
     *
     * Every entry is held by manager+admin and denied to viewer/cashier/operator;
     * the accountant split is asserted per-permission below.
     *
     * @var list<string>
     */
    private array $permissions = [
        'goods-receipt.create-standalone',
        'supplier-invoices.create-pending',
        'supplier-invoices.link-receipts',
        'supplier-invoices.approve-invoice-first',
        'supplier-invoices.manage',
        'payments.pay-supplier',
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
        $this->assertTrue($accountant->hasPermissionTo('supplier-invoices.manage'));
        $this->assertTrue($accountant->hasPermissionTo('payments.pay-supplier'));
    }

    /**
     * F-W2-14 residual (a): reverting a CONFIRMED purchase order rides on
     * `purchase-orders.confirm` (DocumentPolicy::revert), not on the generic
     * `documents.update` a cashier holds. Pinned here so the seeded shape of
     * that permission cannot drift under the policy.
     */
    public function test_purchase_order_revert_permission_is_manager_tier_not_cashier_tier(): void
    {
        $this->assertTrue($this->role('manager')->hasPermissionTo('purchase-orders.confirm'));

        foreach (['viewer', 'cashier', 'operator', 'accountant'] as $roleName) {
            $this->assertFalse(
                $this->role($roleName)->hasPermissionTo('purchase-orders.confirm'),
                "Role [{$roleName}] must not hold purchase-orders.confirm.",
            );
        }

        // ... and the coarse permission it replaces on that route IS held by a
        // cashier — which is exactly why the per-type verdict was needed.
        $this->assertTrue($this->role('cashier')->hasPermissionTo('documents.update'));
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
