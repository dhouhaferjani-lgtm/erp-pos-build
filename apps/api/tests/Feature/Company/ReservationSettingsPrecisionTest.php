<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Precision regression: CompanyController::updateReservationSettings monetary thresholds.
 *
 * Before fix:
 *   number_format((float) '1234.567', 2, '.', '') = '1234.57'
 *   The float cast + number_format silently truncates TND millièmes (3rd decimal).
 *
 * After fix:
 *   CurrencyScale::bcformatStrict('1234.567', scaleResolver->getScale())
 *   With TND company (scale=3) → '1234.567' preserved exactly.
 *
 * Gold assertion: POST TND threshold '1234.567' → stored JSONB value = '1234.567'
 */
final class ReservationSettingsPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'TND Reservation Settings Tenant',
            'slug' => 'tnd-reservation-precision-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'settings' => [],
        ]);

        // TND company → scale = 3
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TND Settings Company',
            'legal_name' => 'TND Settings Company SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin-res-precision-'.uniqid().'@example.com',
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

    /**
     * TND millième (3rd decimal) must be preserved in manager_override_threshold_amount.
     *
     * Float path: number_format((float) '1234.567', 2, '.', '') = '1234.57'  ← WRONG
     * Bcmath path: CurrencyScale::bcformatStrict('1234.567', 3)  = '1234.567' ← CORRECT
     */
    public function test_tnd_threshold_millieme_preserved_in_manager_override_amount(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/reservation-settings", [
                'manager_override_threshold_amount' => '1234.567',
            ]);

        $response->assertOk();

        // Reload fresh from DB to check the stored JSONB value
        $this->company->refresh();
        $settings = $this->company->getReservationSettings();

        $this->assertSame(
            '1234.567',
            $settings->managerOverrideThresholdAmount,
            'TND manager_override_threshold_amount must preserve 3 decimals (millième). '
            ."Got: '{$settings->managerOverrideThresholdAmount}'. "
            .'Fix: CompanyController must use CurrencyScale::bcformatStrict with scaleResolver (not number_format((float))).',
        );
    }

    /**
     * TND millième preserved in goodwill_named_customer_threshold.
     */
    public function test_tnd_threshold_millieme_preserved_in_goodwill_named_customer_threshold(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/reservation-settings", [
                'goodwill_named_customer_threshold' => '500.001',
            ]);

        $response->assertOk();

        $this->company->refresh();
        $settings = $this->company->getReservationSettings();

        $this->assertSame(
            '500.001',
            $settings->goodwillNamedCustomerThreshold,
            'TND goodwill_named_customer_threshold must preserve 3 decimals. '
            ."Got: '{$settings->goodwillNamedCustomerThreshold}'.",
        );
    }

    /**
     * TND millième preserved in daily_refund_cap_per_cashier.
     */
    public function test_tnd_threshold_millieme_preserved_in_daily_refund_cap(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/companies/{$this->company->id}/reservation-settings", [
                'daily_refund_cap_per_cashier' => '999.999',
            ]);

        $response->assertOk();

        $this->company->refresh();
        $settings = $this->company->getReservationSettings();

        $this->assertSame(
            '999.999',
            $settings->dailyRefundCapPerCashier,
            'TND daily_refund_cap_per_cashier must preserve 3 decimals. '
            ."Got: '{$settings->dailyRefundCapPerCashier}'.",
        );
    }

    /**
     * EUR companies (scale=2) must still round to 2 decimal places.
     */
    public function test_eur_company_threshold_rounded_to_2_decimals(): void
    {
        // Create EUR company
        $eurTenant = Tenant::create([
            'name' => 'EUR Precision Tenant',
            'slug' => 'eur-res-precision-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'settings' => [],
        ]);

        $eurCompany = Company::create([
            'tenant_id' => $eurTenant->id,
            'name' => 'EUR Settings Company',
            'legal_name' => 'EUR Settings Company SAS',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($eurCompany->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($eurTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $eurAdmin = User::create([
            'tenant_id' => $eurTenant->id,
            'name' => 'EUR Admin',
            'email' => 'eur-admin-'.uniqid().'@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $eurAdmin->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $eurAdmin->id,
            'company_id' => $eurCompany->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($eurAdmin)
            ->putJson("/api/v1/companies/{$eurCompany->id}/reservation-settings", [
                'manager_override_threshold_amount' => '100.005',
            ]);

        $response->assertOk();

        $eurCompany->refresh();
        $settings = $eurCompany->getReservationSettings();

        // EUR scale=2: bcformatStrict('100.005', 2) → bcadd truncates → '100.00'
        // This is the CORRECT bcmath behaviour: truncation (not float rounding)
        $this->assertSame(
            '100.00',
            $settings->managerOverrideThresholdAmount,
            'EUR manager_override_threshold_amount must be at 2 decimal places. '
            ."Got: '{$settings->managerOverrideThresholdAmount}'.",
        );
    }
}
