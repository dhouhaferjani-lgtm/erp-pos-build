<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * W-6 D5 owner ruling (2026-08-05, "Option B split"):
 * new permissions `reports.financial` (trial balance, P&L, balance sheet,
 * VAT declaration) and `reports.operational` (aged AR/AP, upcoming
 * payments, cash movements, statements); `ledger.view` stays for GL
 * browsing. Role sub-rules: viewer gets NO finance perms; manager gets
 * OPERATIONAL ONLY; accountant and admin get financial + operational +
 * ledger.view. `reports.view` is deprecated this release (kept seeded,
 * gates no route).
 */
final class RolesAndPermissionsFinanceReportSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_holds_all_three_finance_report_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = Role::findByName('admin', 'sanctum');

        $this->assertTrue($admin->hasPermissionTo('reports.financial', 'sanctum'));
        $this->assertTrue($admin->hasPermissionTo('reports.operational', 'sanctum'));
        $this->assertTrue($admin->hasPermissionTo('ledger.view', 'sanctum'));
    }

    public function test_accountant_holds_all_three_finance_report_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $accountant = Role::findByName('accountant', 'sanctum');

        $this->assertTrue($accountant->hasPermissionTo('reports.financial', 'sanctum'));
        $this->assertTrue($accountant->hasPermissionTo('reports.operational', 'sanctum'));
        $this->assertTrue($accountant->hasPermissionTo('ledger.view', 'sanctum'));
        // Gate finding I-1: accountant keeps reports.manage (VAT period
        // generate/close/reopen/file) — it also holds the matching read
        // permission (reports.financial), so there's no mutate-without-read gap.
        $this->assertTrue($accountant->hasPermissionTo('reports.manage', 'sanctum'));
    }

    public function test_manager_holds_operational_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = Role::findByName('manager', 'sanctum');

        $this->assertTrue($manager->hasPermissionTo('reports.operational', 'sanctum'));
        $this->assertFalse($manager->hasPermissionTo('reports.financial', 'sanctum'));
        $this->assertFalse($manager->hasPermissionTo('ledger.view', 'sanctum'));
        // Gate finding I-1 (2026-08-06 review, orchestrator ruling): manager
        // must NOT keep reports.manage either — VAT-period lifecycle mutation
        // (generate/close/reopen/file) is financial, and keeping it while
        // manager lost reports.financial let it file a VAT declaration it
        // cannot read back. See VatPeriodManagerCannotMutateTest for the
        // HTTP-level proof.
        $this->assertFalse($manager->hasPermissionTo('reports.manage', 'sanctum'));
    }

    public function test_viewer_holds_no_finance_report_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewer = Role::findByName('viewer', 'sanctum');

        $this->assertFalse($viewer->hasPermissionTo('reports.financial', 'sanctum'));
        $this->assertFalse($viewer->hasPermissionTo('reports.operational', 'sanctum'));
        $this->assertFalse($viewer->hasPermissionTo('ledger.view', 'sanctum'));
    }

    public function test_cashier_holds_no_finance_report_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $cashier = Role::findByName('cashier', 'sanctum');

        $this->assertFalse($cashier->hasPermissionTo('reports.financial', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('reports.operational', 'sanctum'));
        $this->assertFalse($cashier->hasPermissionTo('ledger.view', 'sanctum'));
    }

    /**
     * m-3 (2026-08-06 gate review): renamed from the misleading
     * `test_reports_view_is_still_seeded_but_gates_nothing_…` — this test
     * only pins the ROLE GRANTS (admin retains reports.view via
     * Permission::all(); no non-admin role is explicitly granted it), which
     * is all a Role-level assertion can prove. It does NOT and cannot pin
     * "no route checks reports.view any more" — that is a route-middleware
     * claim, verified instead by grep in the gate review and by every
     * touched-endpoint HTTP test now asserting reports.financial /
     * reports.operational instead of reports.view.
     */
    public function test_reports_view_permission_row_still_exists_and_is_only_explicitly_granted_to_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = Role::findByName('admin', 'sanctum');
        $accountant = Role::findByName('accountant', 'sanctum');
        $manager = Role::findByName('manager', 'sanctum');
        $viewer = Role::findByName('viewer', 'sanctum');

        $this->assertTrue($admin->hasPermissionTo('reports.view', 'sanctum'));
        $this->assertFalse($accountant->hasPermissionTo('reports.view', 'sanctum'));
        $this->assertFalse($manager->hasPermissionTo('reports.view', 'sanctum'));
        $this->assertFalse($viewer->hasPermissionTo('reports.view', 'sanctum'));
    }
}
