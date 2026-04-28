<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Feature tests for FraudSettingsController cash-control fields (Task 23).
 *
 * Covers:
 * - GET show returns new cash-control defaults for a fresh company
 * - PATCH with valid cash-control payload + permission → 200
 * - PATCH without pos.configure_cash_count → 403
 * - PATCH with soft >= hard → 422 (cross-field sanity)
 * - PATCH with invalid email severity → 422 (Rule::in)
 * - PATCH touching only non-cash-control fields does NOT require pos.configure_cash_count
 */
final class FraudSettingsControllerCashControlsTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private const CASH_CONTROL_PERMISSION = 'pos.configure_cash_count';

    private const FRAUD_SETTINGS_VIEW = 'fraud-settings.view';

    private const FRAUD_SETTINGS_UPDATE = 'fraud-settings.update';

    private Tenant $tenant;

    private Company $company;

    /** User with fraud-settings.view + fraud-settings.update + pos.configure_cash_count */
    private User $adminUser;

    /** User with fraud-settings.view + fraud-settings.update but WITHOUT pos.configure_cash_count */
    private User $legacyUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-cash-ctrl',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // Set the permissions team scope before creating/granting permissions.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        // Seed the three permissions this test cares about (Task 25 will add them to the
        // main seeder; for now we create them inline so the test is self-contained).
        Permission::findOrCreate(self::FRAUD_SETTINGS_VIEW, 'sanctum');
        Permission::findOrCreate(self::FRAUD_SETTINGS_UPDATE, 'sanctum');
        Permission::findOrCreate(self::CASH_CONTROL_PERMISSION, 'sanctum');

        // Admin user — has all three permissions.
        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@fraud-ctrl-test.example',
            'password' => bcrypt('Password1!'),
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->givePermissionTo([
            self::FRAUD_SETTINGS_VIEW,
            self::FRAUD_SETTINGS_UPDATE,
            self::CASH_CONTROL_PERMISSION,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Legacy user — has fraud-settings.* but NOT pos.configure_cash_count.
        $this->legacyUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Legacy User',
            'email' => 'legacy@fraud-ctrl-test.example',
            'password' => bcrypt('Password1!'),
            'status' => UserStatus::Active,
        ]);
        $this->legacyUser->givePermissionTo([
            self::FRAUD_SETTINGS_VIEW,
            self::FRAUD_SETTINGS_UPDATE,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->legacyUser->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);
    }

    /**
     * Test 1: GET /fraud-settings returns vertical-aware defaults for a fresh company.
     *
     * Since PR #37 the EnsureFraudSettingsOnCompanyCreated listener auto-provisions
     * a CompanyFraudSettings row whenever a Company is created. This test's tenant
     * has no `vertical` set, so the listener picks non-automotive defaults
     * (require_blind_cash_count=false). `is_configured` therefore reflects "a row
     * exists" — which is now true for every newly-created company.
     */
    public function test_show_returns_cash_control_defaults_for_fresh_company(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/fraud-settings');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame('1.0000', $data['cash_variance_over_soft']);
        $this->assertSame('20.0000', $data['cash_variance_over_hard']);
        $this->assertFalse($data['require_blind_cash_count']);
        $this->assertTrue($data['require_manager_pin_above_hard']);
        $this->assertSame('none', $data['cash_variance_email_severity']);
        $this->assertTrue($data['is_configured']);
    }

    /**
     * Test 2: PATCH with valid cash-control payload + permission → 200 with updated values.
     */
    public function test_update_cash_control_fields_with_permission_returns_200(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'cash_variance_over_hard' => '50.0000',
                'cash_variance_email_severity' => 'warning',
            ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Fraud detection settings updated successfully']);

        $data = $response->json('data');
        $this->assertSame('50.0000', $data['cash_variance_over_hard']);
        $this->assertSame('warning', $data['cash_variance_email_severity']);
    }

    /**
     * Test 3: PATCH touching a cash-control field without pos.configure_cash_count → 403.
     */
    public function test_update_cash_control_fields_without_permission_returns_403(): void
    {
        $response = $this->actingAs($this->legacyUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'cash_variance_over_hard' => '50.0000',
            ]);

        $response->assertForbidden();
    }

    /**
     * Test 4: PATCH with over_soft >= over_hard → 422 with meaningful error message.
     */
    public function test_update_with_soft_equal_to_hard_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'cash_variance_over_soft' => '20.0000',
                'cash_variance_over_hard' => '20.0000',
            ]);

        $this->assertApiValidationErrors($response, ['cash_variance_over_soft']);
        $this->assertStringContainsString(
            'over_soft',
            (string) ($response->json('error.errors.cash_variance_over_soft.0') ?? ''),
        );
    }

    /**
     * Test 4b: PATCH with under_soft >= under_hard → 422.
     */
    public function test_update_with_under_soft_greater_than_under_hard_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'cash_variance_under_soft' => '25.0000',
                'cash_variance_under_hard' => '10.0000',
            ]);

        $this->assertApiValidationErrors($response, ['cash_variance_under_soft']);
    }

    /**
     * Test 5: PATCH with invalid severity value → 422.
     */
    public function test_update_with_invalid_email_severity_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'cash_variance_email_severity' => 'typo',
            ]);

        $this->assertApiValidationErrors($response, ['cash_variance_email_severity']);
    }

    /**
     * Test 7: Partial update — only over_soft sent; persisted over_hard is lower → 422.
     *
     * This guards against the 500→422 gap: without loading persisted values the
     * cross-field check would short-circuit (both halves not in $validated), and
     * the DB CHECK constraint would fire instead → 500 QueryException.
     *
     * The persisted row is provisioned automatically by the
     * EnsureFraudSettingsOnCompanyCreated listener (PR #37), with over_hard=20
     * from the non-automotive vertical defaults — no explicit seed is needed.
     */
    public function test_partial_update_over_soft_above_persisted_hard_returns_422(): void
    {
        // Listener-provisioned row already has over_hard = 20.0000.
        // Send only over_soft = 25 — above the persisted over_hard of 20.
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'cash_variance_over_soft' => '25.0000',
            ]);

        $this->assertApiValidationErrors($response, ['cash_variance_over_soft']);
    }

    /**
     * Test 8: Partial update — only under_soft sent; persisted under_hard is lower → 422.
     *
     * The persisted row is provisioned automatically by the
     * EnsureFraudSettingsOnCompanyCreated listener (PR #37), with under_hard=20
     * from the non-automotive vertical defaults — no explicit seed is needed.
     */
    public function test_partial_update_under_soft_above_persisted_hard_returns_422(): void
    {
        // Listener-provisioned row already has under_hard = 20.0000.
        // Send only under_soft = 25 — above the persisted under_hard of 20.
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'cash_variance_under_soft' => '25.0000',
            ]);

        $this->assertApiValidationErrors($response, ['cash_variance_under_soft']);
    }

    /**
     * Test 6: PATCH touching ONLY non-cash-control fields does NOT require pos.configure_cash_count.
     *
     * The legacy user has fraud-settings.update but NOT pos.configure_cash_count.
     * A payload that only touches alert_enabled must succeed with 200.
     */
    public function test_update_non_cash_control_fields_does_not_require_cash_control_permission(): void
    {
        $response = $this->actingAs($this->legacyUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'alert_enabled' => false,
            ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Fraud detection settings updated successfully']);
        $this->assertFalse($response->json('data.alert_enabled'));
    }
}
