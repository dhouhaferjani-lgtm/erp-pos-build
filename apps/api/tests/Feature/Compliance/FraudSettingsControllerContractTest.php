<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contract test for /api/v1/fraud-settings (M5).
 *
 * Locks the API response shape (DTO key set + scalar types) returned by
 * GET / PATCH / POST reset so we can swap CompanyFraudSettings::toArray()
 * for a typed CompanyFraudSettingsData DTO without changing the wire
 * payload that the FE consumes.
 *
 * The hand-written FraudSettings type in
 *   apps/web/src/features/compliance/types/fraud.ts
 * is being deleted in favor of a generated DTO emitted from
 * CompanyFraudSettingsData; this test guarantees the snake_case keys
 * the FE relies on remain identical.
 */
final class FraudSettingsControllerContractTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'id',
        'company_id',
        'abandoned_draft_threshold',
        'time_window_days',
        'alert_emails',
        'alert_enabled',
        'auto_trigger_counting',
        'auto_restrict_access',
        'cash_variance_over_soft',
        'cash_variance_over_hard',
        'cash_variance_under_soft',
        'cash_variance_under_hard',
        'require_blind_cash_count',
        'require_manager_pin_above_hard',
        'cash_variance_email_severity',
        'created_at',
        'updated_at',
        'is_configured',
    ];

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Contract Tenant',
            'slug' => 'fraud-settings-contract',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Contract Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('fraud-settings.view', 'sanctum');
        Permission::findOrCreate('fraud-settings.update', 'sanctum');
        Permission::findOrCreate('pos.configure_cash_count', 'sanctum');

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'email' => 'admin@fraud-contract.example',
            'password' => bcrypt('Password1!'),
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->givePermissionTo([
            'fraud-settings.view',
            'fraud-settings.update',
            'pos.configure_cash_count',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    public function test_show_returns_canonical_fraud_settings_dto_keys(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/fraud-settings');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertIsArray($data);
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $data, "missing key {$key} in /fraud-settings show payload");
        }

        $this->assertSame($this->company->id, $data['company_id']);
        $this->assertIsBool($data['is_configured']);
        $this->assertIsBool($data['alert_enabled']);
        $this->assertIsBool($data['require_blind_cash_count']);
        $this->assertIsBool($data['require_manager_pin_above_hard']);
        $this->assertIsString($data['cash_variance_over_soft']);
        $this->assertIsInt($data['abandoned_draft_threshold']);
        // alert_emails is array<string>|null in the persisted model.
        $this->assertTrue(is_array($data['alert_emails']) || $data['alert_emails'] === null);
    }

    public function test_update_returns_canonical_fraud_settings_dto_keys(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/fraud-settings', [
                'cash_variance_over_hard' => '50.0000',
                'cash_variance_email_severity' => 'warning',
            ]);

        $response->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $data, "missing key {$key} in /fraud-settings update payload");
        }
        $this->assertSame('50.0000', $data['cash_variance_over_hard']);
        $this->assertSame('warning', $data['cash_variance_email_severity']);
        $this->assertTrue($data['is_configured']);
    }

    public function test_reset_returns_canonical_fraud_settings_dto_keys(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/fraud-settings/reset');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $data, "missing key {$key} in /fraud-settings reset payload");
        }
        $this->assertTrue($data['is_configured']);
    }

    /**
     * Lane C M2/M3 gate finding I-2 — `reset()` restores the CASH-CONTROL
     * defaults this endpoint's UI is about. The three refund-exposure
     * ceilings have no admin UI yet (owner ruling: DB-only until post-launch),
     * so a "reset fraud settings" click must not silently WIDEN a ceiling
     * nobody can see, on a screen that says nothing about refunds.
     */
    public function test_reset_preserves_the_refund_exposure_policies(): void
    {
        CompanyFraudSettings::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'offline_refund_count_ceiling' => 2,
                'offline_refund_value_ceiling' => '120.0000',
                'online_required_refund_threshold' => '40.0000',
            ]
        );

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/fraud-settings/reset')
            ->assertOk();

        $settings = CompanyFraudSettings::query()
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $this->assertSame(2, (int) $settings->offline_refund_count_ceiling);
        $this->assertSame('120.0000', (string) $settings->offline_refund_value_ceiling);
        $this->assertSame('40.0000', (string) $settings->online_required_refund_threshold);
        // …while the cash-control defaults it IS about were genuinely reset.
        $this->assertSame('20.0000', (string) $settings->cash_variance_over_hard);
    }

    /**
     * A company with NO row still gets sane refund-exposure values on reset —
     * excluding them from the payload must not create a row with 0/NULL
     * ceilings (the model's own $attributes supply the seeded defaults).
     */
    public function test_reset_on_a_company_with_no_row_still_seeds_the_refund_exposure_defaults(): void
    {
        CompanyFraudSettings::query()->where('company_id', $this->company->id)->delete();

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/fraud-settings/reset')
            ->assertOk();

        $settings = CompanyFraudSettings::query()
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $this->assertSame(
            CompanyFraudSettings::DEFAULT_OFFLINE_REFUND_COUNT_CEILING,
            (int) $settings->offline_refund_count_ceiling
        );
        $this->assertSame(
            CompanyFraudSettings::DEFAULT_OFFLINE_REFUND_VALUE_CEILING,
            (string) $settings->offline_refund_value_ceiling
        );
        $this->assertSame(
            CompanyFraudSettings::DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
            (string) $settings->online_required_refund_threshold
        );
    }
}
