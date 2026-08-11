<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
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

    public function test_cosmetic_editor_can_update_cosmetic_company_fields(): void
    {
        $editor = $this->userWithRole('viewer');
        $editor->givePermissionTo('settings.update');

        $response = $this->actingAs($editor)->putJson("/api/v1/companies/{$this->company->id}", [
            'name' => 'Cosmetic Company Name',
            'phone' => '+216 70 000 000',
        ]);

        $response->assertOk();
        $this->assertSame('Cosmetic Company Name', $this->company->refresh()->name);
    }

    public function test_cosmetic_editor_cannot_update_company_fiscal_identity(): void
    {
        $editor = $this->userWithRole('viewer');
        $editor->givePermissionTo('settings.update');
        $originalIdentity = $this->company->only([
            'legal_name',
            'tax_id',
            'registration_number',
            'vat_number',
        ]);

        $response = $this->actingAs($editor)->putJson("/api/v1/companies/{$this->company->id}", [
            'legal_name' => 'Bypassed Legal Name',
            'tax_id' => 'TN0000000A',
            'registration_number' => 'BYPASS-REG',
            'vat_number' => 'BYPASS-VAT',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath(
                'error.message',
                'You do not have permission to update the company fiscal identity.',
            );

        $this->company->refresh();
        $this->assertSame($originalIdentity, $this->company->only([
            'legal_name',
            'tax_id',
            'registration_number',
            'vat_number',
        ]));
    }

    public function test_admin_company_fiscal_identity_update_records_old_new_audit_event(): void
    {
        $admin = $this->userWithRole('admin');
        $originalIdentity = $this->company->only([
            'legal_name',
            'tax_id',
            'registration_number',
            'vat_number',
        ]);

        $response = $this->actingAs($admin)->putJson("/api/v1/companies/{$this->company->id}", [
            'legal_name' => 'Updated Company Legal Name',
            'tax_id' => 'TN1234567A',
            'registration_number' => 'REG-123',
            'vat_number' => 'VAT-123',
        ]);

        $response->assertOk();

        $auditEvent = AuditEvent::query()
            ->where('event_type', 'company.fiscal_identity_updated')
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($auditEvent);
        $this->assertSame('company', $auditEvent->aggregate_type);
        $this->assertSame($this->company->id, $auditEvent->aggregate_id);
        $this->assertEquals([
            'legal_name' => ['old' => $originalIdentity['legal_name'], 'new' => 'Updated Company Legal Name'],
            'tax_id' => ['old' => $originalIdentity['tax_id'], 'new' => 'TN1234567A'],
            'registration_number' => ['old' => $originalIdentity['registration_number'], 'new' => 'REG-123'],
            'vat_number' => ['old' => $originalIdentity['vat_number'], 'new' => 'VAT-123'],
        ], $auditEvent->payload['changes']);
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
