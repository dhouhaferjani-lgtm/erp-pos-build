<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

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
 * Feature tests for GET /api/v1/pos/fraud-settings (Task 24).
 *
 * Covers:
 * 1. Returns defaults when no CompanyFraudSettings row exists.
 * 2. Returns persisted values when the row is configured.
 */
final class FraudSettingsPosControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Fraud Settings POS Tenant',
            'slug' => 'fraud-settings-pos-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'POS Test Shop',
            'legal_name' => 'POS Test Shop LLC',
            'tax_id' => 'TAX888',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier POS',
            'email' => 'cashier@fraud-pos-test.local',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->cashier->givePermissionTo('pos.operate_terminal');

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
    }

    /**
     * Test 1: Returns default fraud settings when no DB row exists for the company.
     */
    public function test_returns_defaults_when_no_row_exists(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/fraud-settings');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame($this->company->id, $data['companyId']);
        $this->assertSame('1.0000', $data['cashVarianceOverSoft']);
        $this->assertSame('20.0000', $data['cashVarianceOverHard']);
        $this->assertSame('1.0000', $data['cashVarianceUnderSoft']);
        $this->assertSame('20.0000', $data['cashVarianceUnderHard']);
        $this->assertTrue($data['requireBlindCashCount']);
        $this->assertTrue($data['requireManagerPinAboveHard']);
        $this->assertSame('none', $data['cashVarianceEmailSeverity']);
    }

    /**
     * Test 2: Returns persisted values when a CompanyFraudSettings row is configured.
     */
    public function test_returns_persisted_values_when_row_exists(): void
    {
        CompanyFraudSettings::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'cash_variance_over_soft' => '5.0000',
                'cash_variance_over_hard' => '50.0000',
                'cash_variance_under_soft' => '3.0000',
                'cash_variance_under_hard' => '30.0000',
                'require_blind_cash_count' => true,
                'require_manager_pin_above_hard' => false,
                'cash_variance_email_severity' => 'warning',
            ]
        );

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/fraud-settings');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame($this->company->id, $data['companyId']);
        $this->assertSame('5.0000', $data['cashVarianceOverSoft']);
        $this->assertSame('50.0000', $data['cashVarianceOverHard']);
        $this->assertSame('3.0000', $data['cashVarianceUnderSoft']);
        $this->assertSame('30.0000', $data['cashVarianceUnderHard']);
        $this->assertTrue($data['requireBlindCashCount']);
        $this->assertFalse($data['requireManagerPinAboveHard']);
        $this->assertSame('warning', $data['cashVarianceEmailSeverity']);
    }
}
