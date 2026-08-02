<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for docs/superpowers/tickets/2026-08-02-company-update-route-unauthorized.md (P0).
 *
 * `PUT /companies/{id}` previously carried NO permission gate at all —
 * `UpdateCompanyRequest::authorize()` returned `true` unconditionally and the
 * route itself had no `can:` middleware. Any authenticated tenant user,
 * including the lowest-privilege seeded role (`viewer`), could mutate
 * company-wide fiscal/discount config: `discount_floor_mode`,
 * `default_max_discount_percent`, `tax_status`, etc.
 *
 * Fixed by adding `->middleware('can:settings.update')` to the route
 * (Company/routes.php), mirroring the sibling `PATCH /settings/company`
 * route (CompanySettingsController::update, gated the same way) which was
 * already correctly gated. Only the `admin` role holds `settings.update` in
 * RolesAndPermissionsSeeder (manager holds `settings.view`/`settings.manage`
 * but NOT `settings.update`).
 *
 * The same audit found two sibling mutations in the same route file with the
 * identical gap — `PUT .../reservation-settings` (inline `$request->validate()`,
 * no authorization at all) and `PUT .../receipt-settings`
 * (`UpdateReceiptSettingsRequest::authorize()` also unconditionally `true`).
 * Both are gated here under the same `settings.update` permission family.
 */
final class CompanyUpdateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);

        return $user;
    }

    // ── PUT /companies/{id} ─────────────────────────────────────────────

    public function test_viewer_cannot_update_company(): void
    {
        $viewer = $this->userWithRole('viewer');

        $response = $this->actingAs($viewer)->putJson("/api/v1/companies/{$this->company->id}", [
            'default_max_discount_percent' => '50.00',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('companies', [
            'id' => $this->company->id,
            'default_max_discount_percent' => '50.00',
        ]);
    }

    public function test_cashier_cannot_update_company(): void
    {
        $cashier = $this->userWithRole('cashier');

        $response = $this->actingAs($cashier)->putJson("/api/v1/companies/{$this->company->id}", [
            'default_max_discount_percent' => '50.00',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_update_company(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin)->putJson("/api/v1/companies/{$this->company->id}", [
            'default_max_discount_percent' => '50.00',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'default_max_discount_percent' => '50.00',
        ]);
    }

    // ── PUT /companies/{id}/reservation-settings (sibling finding) ─────

    public function test_viewer_cannot_update_reservation_settings(): void
    {
        $viewer = $this->userWithRole('viewer');

        $response = $this->actingAs($viewer)->putJson(
            "/api/v1/companies/{$this->company->id}/reservation-settings",
            ['sales_order_expiry_days' => 10]
        );

        $response->assertForbidden();
    }

    public function test_admin_can_update_reservation_settings(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin)->putJson(
            "/api/v1/companies/{$this->company->id}/reservation-settings",
            ['sales_order_expiry_days' => 10]
        );

        $response->assertOk();
    }

    // ── PUT /companies/{id}/receipt-settings (sibling finding) ─────────

    public function test_viewer_cannot_update_receipt_settings(): void
    {
        $viewer = $this->userWithRole('viewer');

        $response = $this->actingAs($viewer)->putJson(
            "/api/v1/companies/{$this->company->id}/receipt-settings",
            ['auto_print_receipts' => true]
        );

        $response->assertForbidden();
    }

    public function test_admin_can_update_receipt_settings(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin)->putJson(
            "/api/v1/companies/{$this->company->id}/receipt-settings",
            ['auto_print_receipts' => true]
        );

        $response->assertOk();
    }
}
