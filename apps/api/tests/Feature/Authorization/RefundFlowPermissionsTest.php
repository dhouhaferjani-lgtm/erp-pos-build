<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verify that all 16 refund-flow permissions from spec §3.8 are present
 * in the seeder and assigned to the correct roles.
 *
 * Admin gets all permissions via Permission::all().
 * Manager gets the operations-level subset.
 * Cashier gets only the baseline permissions (search_recent + redeem).
 * Cashier does NOT get manager-only permissions.
 */
final class RefundFlowPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -------------------------------------------------------------------------
    // Admin
    // -------------------------------------------------------------------------

    public function test_admin_has_all_refund_permissions(): void
    {
        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->firstOrFail();

        $allRefundPermissions = [
            'pos.search_customer_recent_purchases',
            'pos.search_customer_full_history',
            'pos.search_customer_cross_company',
            'pos.refund_above_threshold',
            'pos.refund_no_receipt',
            'pos.refund_extend_daily_cap',
            'pos.issue_goodwill_voucher',
            'pos.issue_goodwill_voucher_high_value',
            'pos.void_voucher',
            'pos.extend_voucher_expiry',
            'pos.transfer_voucher',
            'pos.redeem_voucher',
            'pos.refund_destination_override',
            'pos.refund_voucher_to_cash',
            'pos.fiscal_schema_cutover',
            'pos.rotate_qr_signing_key',
        ];

        foreach ($allRefundPermissions as $permission) {
            $this->assertTrue(
                $admin->hasPermissionTo($permission),
                "Admin should have permission: {$permission}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Cashier — basic permissions only
    // -------------------------------------------------------------------------

    public function test_cashier_has_basic_refund_permissions(): void
    {
        $cashier = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->firstOrFail();

        $this->assertTrue($cashier->hasPermissionTo('pos.search_customer_recent_purchases'));
        $this->assertTrue($cashier->hasPermissionTo('pos.redeem_voucher'));
    }

    public function test_cashier_does_not_have_manager_permissions(): void
    {
        $cashier = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->firstOrFail();

        $managerOnlyPermissions = [
            'pos.refund_above_threshold',
            'pos.refund_no_receipt',
            'pos.refund_extend_daily_cap',
            'pos.issue_goodwill_voucher',
            'pos.void_voucher',
            'pos.refund_destination_override',
            'pos.refund_voucher_to_cash',
            'pos.search_customer_full_history',
        ];

        foreach ($managerOnlyPermissions as $permission) {
            $this->assertFalse(
                $cashier->hasPermissionTo($permission),
                "Cashier should NOT have permission: {$permission}"
            );
        }
    }

    public function test_cashier_does_not_have_admin_only_permissions(): void
    {
        $cashier = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->firstOrFail();

        $adminOnlyPermissions = [
            'pos.search_customer_cross_company',
            'pos.issue_goodwill_voucher_high_value',
            'pos.extend_voucher_expiry',
            'pos.transfer_voucher',
            'pos.fiscal_schema_cutover',
            'pos.rotate_qr_signing_key',
        ];

        foreach ($adminOnlyPermissions as $permission) {
            $this->assertFalse(
                $cashier->hasPermissionTo($permission),
                "Cashier should NOT have admin-only permission: {$permission}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Manager
    // -------------------------------------------------------------------------

    public function test_manager_has_operations_level_refund_permissions(): void
    {
        $manager = Role::where('name', 'manager')->where('guard_name', 'sanctum')->firstOrFail();

        $managerPermissions = [
            'pos.search_customer_recent_purchases',
            'pos.search_customer_full_history',
            'pos.refund_above_threshold',
            'pos.refund_no_receipt',
            'pos.refund_extend_daily_cap',
            'pos.issue_goodwill_voucher',
            'pos.void_voucher',
            'pos.redeem_voucher',
            'pos.refund_destination_override',
            'pos.refund_voucher_to_cash',
        ];

        foreach ($managerPermissions as $permission) {
            $this->assertTrue(
                $manager->hasPermissionTo($permission),
                "Manager should have permission: {$permission}"
            );
        }
    }

    public function test_manager_does_not_have_admin_only_permissions(): void
    {
        $manager = Role::where('name', 'manager')->where('guard_name', 'sanctum')->firstOrFail();

        $adminOnlyPermissions = [
            'pos.search_customer_cross_company',
            'pos.issue_goodwill_voucher_high_value',
            'pos.extend_voucher_expiry',
            'pos.transfer_voucher',
            'pos.fiscal_schema_cutover',
            'pos.rotate_qr_signing_key',
        ];

        foreach ($adminOnlyPermissions as $permission) {
            $this->assertFalse(
                $manager->hasPermissionTo($permission),
                "Manager should NOT have admin-only permission: {$permission}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // All 16 permissions exist in the DB
    // -------------------------------------------------------------------------

    public function test_all_sixteen_refund_flow_permissions_exist_in_database(): void
    {
        $allPermissions = [
            'pos.search_customer_recent_purchases',
            'pos.search_customer_full_history',
            'pos.search_customer_cross_company',
            'pos.refund_above_threshold',
            'pos.refund_no_receipt',
            'pos.refund_extend_daily_cap',
            'pos.issue_goodwill_voucher',
            'pos.issue_goodwill_voucher_high_value',
            'pos.void_voucher',
            'pos.extend_voucher_expiry',
            'pos.transfer_voucher',
            'pos.redeem_voucher',
            'pos.refund_destination_override',
            'pos.refund_voucher_to_cash',
            'pos.fiscal_schema_cutover',
            'pos.rotate_qr_signing_key',
        ];

        foreach ($allPermissions as $permName) {
            $exists = Permission::where('name', $permName)
                ->where('guard_name', 'sanctum')
                ->exists();

            $this->assertTrue($exists, "Permission '{$permName}' should exist in the database.");
        }
    }
}
