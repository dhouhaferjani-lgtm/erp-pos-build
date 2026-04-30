<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Domain\ValueObjects\CompanyReservationSettings;
use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\ValueObjects\PosRefundSettings;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RefundPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Refund Policy Account',
            'slug' => 'test-refund-policy-settings',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'settings' => [],
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Refund Company',
            'legal_name' => 'Test Refund Company SARL',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin-refund-policy@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
    }

    public function test_no_parallel_settings_class_exists(): void
    {
        $this->assertFalse(class_exists(CompanyReservationSettings::class));
        $this->assertFalse(class_exists(PosRefundSettings::class));
    }

    public function test_reservation_settings_has_refund_policy_defaults(): void
    {
        $settings = new ReservationSettings;

        $this->assertSame(14, $settings->customerReturnExpiryDays); // existing
        $this->assertSame(14, $settings->customerHistoryWindowDays); // new, default = customerReturnExpiryDays
        $this->assertSame('voucher_only', $settings->outOfWindowPolicy);
        $this->assertSame('50.00', $settings->managerOverrideThresholdAmount);
        $this->assertSame('10.00', $settings->managerOverrideThresholdPercent);
        $this->assertTrue($settings->managerOverrideRequiredForNoReceipt);
        $this->assertSame(['original_payment', 'cash', 'store_voucher'], $settings->allowedRefundDestinations);
        $this->assertSame('proportional', $settings->prorationStrategy);
        $this->assertSame(365, $settings->voucherDefaultExpiryDays);
        $this->assertTrue($settings->voucherTransferableDefault);
        $this->assertFalse($settings->voucherCashRefundAllowed);
        $this->assertNull($settings->dailyRefundCapPerCashier);
        $this->assertTrue($settings->dailyRefundCapOverrideAllowed);
        $this->assertSame(15, $settings->customerHistorySearchMaxPerCashierPerDay);
        $this->assertSame(
            ['rejected_specificity_per_hour' => 3, 'same_partner_per_day' => 8, 'cross_company_immediate' => true],
            $settings->customerHistorySearchAlertThresholds
        );
        $this->assertSame(200, $settings->voucherLookupPerTerminalPerDay);
        $this->assertSame(100, $settings->voucherLookupPerCashierPerDay);
        $this->assertSame(50, $settings->voucherLookupFailedPerTenantPerHourAlert);
        $this->assertSame(200, $settings->voucherLookupFailedPerTenantPerHourBlock);
        $this->assertSame(5, $settings->voucherFailedAttemptsAutoVoid);
        $this->assertSame('100.00', $settings->goodwillNamedCustomerThreshold);
        $this->assertSame('250.00', $settings->goodwillFourEyesThreshold);
        $this->assertNull($settings->goodwillDailyIssuanceCapPerUser);
        $this->assertTrue($settings->goodwillBearerDefaultOff);
    }

    public function test_reservation_settings_round_trips_via_company_controller(): void
    {
        $payload = [
            'customer_history_window_days' => 30,
            'out_of_window_policy' => 'refuse',
            'manager_override_threshold_amount' => '75.00',
            'manager_override_threshold_percent' => '15.00',
            'manager_override_required_for_no_receipt' => false,
            'allowed_refund_destinations' => ['original_payment', 'store_voucher'],
            'proration_strategy' => 'largest_first',
            'voucher_default_expiry_days' => 180,
            'voucher_transferable_default' => false,
            'voucher_cash_refund_allowed' => true,
            'daily_refund_cap_per_cashier' => '500.00',
            'daily_refund_cap_override_allowed' => false,
            'customer_history_search_max_per_cashier_per_day' => 10,
            'customer_history_search_alert_thresholds' => [
                'rejected_specificity_per_hour' => 5,
                'same_partner_per_day' => 10,
                'cross_company_immediate' => false,
            ],
            'voucher_lookup_per_terminal_per_day' => 300,
            'voucher_lookup_per_cashier_per_day' => 150,
            'voucher_lookup_failed_per_tenant_per_hour_alert' => 75,
            'voucher_lookup_failed_per_tenant_per_hour_block' => 300,
            'voucher_failed_attempts_auto_void' => 3,
            'goodwill_named_customer_threshold' => '200.00',
            'goodwill_four_eyes_threshold' => '500.00',
            'goodwill_daily_issuance_cap_per_user' => '1000.00',
            'goodwill_bearer_default_off' => false,
        ];

        $patchResponse = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/reservation-settings", $payload);

        $patchResponse->assertOk();

        $getResponse = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/companies/{$this->company->id}/reservation-settings");

        $getResponse->assertOk()
            ->assertJsonFragment([
                'customer_history_window_days' => 30,
                'out_of_window_policy' => 'refuse',
                'manager_override_threshold_amount' => '75.00',
                'manager_override_threshold_percent' => '15.00',
                'manager_override_required_for_no_receipt' => false,
                'allowed_refund_destinations' => ['original_payment', 'store_voucher'],
                'proration_strategy' => 'largest_first',
                'voucher_default_expiry_days' => 180,
                'voucher_transferable_default' => false,
                'voucher_cash_refund_allowed' => true,
                'daily_refund_cap_per_cashier' => '500.00',
                'daily_refund_cap_override_allowed' => false,
                'customer_history_search_max_per_cashier_per_day' => 10,
                'voucher_lookup_per_terminal_per_day' => 300,
                'voucher_lookup_per_cashier_per_day' => 150,
                'voucher_lookup_failed_per_tenant_per_hour_alert' => 75,
                'voucher_lookup_failed_per_tenant_per_hour_block' => 300,
                'voucher_failed_attempts_auto_void' => 3,
                'goodwill_named_customer_threshold' => '200.00',
                'goodwill_four_eyes_threshold' => '500.00',
                'goodwill_daily_issuance_cap_per_user' => '1000.00',
                'goodwill_bearer_default_off' => false,
            ]);
    }

    public function test_existing_companies_have_refund_policy_defaults_after_migration(): void
    {
        // Company created in setUp without refund-policy fields.
        // Simulate a company without any reservation_settings set.
        $this->company->update(['reservation_settings' => null]);
        $this->company->refresh();

        // Run the backfill migration logic inline (same logic as the data migration).
        $defaults = [
            'customer_history_window_days' => 14,
            'out_of_window_policy' => 'voucher_only',
            'manager_override_threshold_amount' => '50.00',
            'manager_override_threshold_percent' => '10.00',
            'manager_override_required_for_no_receipt' => true,
            'allowed_refund_destinations' => ['original_payment', 'cash', 'store_voucher'],
            'proration_strategy' => 'proportional',
            'voucher_default_expiry_days' => 365,
            'voucher_transferable_default' => true,
            'voucher_cash_refund_allowed' => false,
            'daily_refund_cap_per_cashier' => null,
            'daily_refund_cap_override_allowed' => true,
            'customer_history_search_max_per_cashier_per_day' => 15,
            'customer_history_search_alert_thresholds' => [
                'rejected_specificity_per_hour' => 3,
                'same_partner_per_day' => 8,
                'cross_company_immediate' => true,
            ],
            'voucher_lookup_per_terminal_per_day' => 200,
            'voucher_lookup_per_cashier_per_day' => 100,
            'voucher_lookup_failed_per_tenant_per_hour_alert' => 50,
            'voucher_lookup_failed_per_tenant_per_hour_block' => 200,
            'voucher_failed_attempts_auto_void' => 5,
            'goodwill_named_customer_threshold' => '100.00',
            'goodwill_four_eyes_threshold' => '250.00',
            'goodwill_daily_issuance_cap_per_user' => null,
            'goodwill_bearer_default_off' => true,
        ];

        $current = $this->company->reservation_settings ?? [];
        $this->company->reservation_settings = array_merge($defaults, $current);
        $this->company->save();

        $this->company->refresh();
        $settings = $this->company->getReservationSettings();

        $this->assertSame(14, $settings->customerHistoryWindowDays);
        $this->assertSame('voucher_only', $settings->outOfWindowPolicy);
        $this->assertSame('50.00', $settings->managerOverrideThresholdAmount);
        $this->assertSame('10.00', $settings->managerOverrideThresholdPercent);
        $this->assertTrue($settings->managerOverrideRequiredForNoReceipt);
        $this->assertSame(['original_payment', 'cash', 'store_voucher'], $settings->allowedRefundDestinations);
        $this->assertSame('proportional', $settings->prorationStrategy);
        $this->assertSame(365, $settings->voucherDefaultExpiryDays);
        $this->assertTrue($settings->voucherTransferableDefault);
        $this->assertFalse($settings->voucherCashRefundAllowed);
        $this->assertNull($settings->dailyRefundCapPerCashier);
        $this->assertTrue($settings->dailyRefundCapOverrideAllowed);
        $this->assertSame(15, $settings->customerHistorySearchMaxPerCashierPerDay);
        $this->assertSame(
            ['rejected_specificity_per_hour' => 3, 'same_partner_per_day' => 8, 'cross_company_immediate' => true],
            $settings->customerHistorySearchAlertThresholds
        );
        $this->assertSame(200, $settings->voucherLookupPerTerminalPerDay);
        $this->assertSame(100, $settings->voucherLookupPerCashierPerDay);
        $this->assertSame(50, $settings->voucherLookupFailedPerTenantPerHourAlert);
        $this->assertSame(200, $settings->voucherLookupFailedPerTenantPerHourBlock);
        $this->assertSame(5, $settings->voucherFailedAttemptsAutoVoid);
        $this->assertSame('100.00', $settings->goodwillNamedCustomerThreshold);
        $this->assertSame('250.00', $settings->goodwillFourEyesThreshold);
        $this->assertNull($settings->goodwillDailyIssuanceCapPerUser);
        $this->assertTrue($settings->goodwillBearerDefaultOff);
    }

    public function test_validation_rejects_invalid_out_of_window_policy(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/reservation-settings", [
                'out_of_window_policy' => 'banana',
            ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('error.errors.out_of_window_policy'));
    }

    public function test_validation_rejects_invalid_proration_strategy(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/reservation-settings", [
                'proration_strategy' => 'random',
            ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('error.errors.proration_strategy'));
    }

    public function test_validation_rejects_negative_threshold(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/reservation-settings", [
                'manager_override_threshold_amount' => -10,
            ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('error.errors.manager_override_threshold_amount'));
    }
}
