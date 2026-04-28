<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

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

/**
 * Contract test for GET /api/v1/pos/fraud-settings JSON key casing.
 *
 * Asserts that all keys in data{} are camelCase (matching FraudSettingsResponse
 * in the POS TypeScript client) and that no snake_case keys leak through.
 */
final class FraudSettingsPosControllerContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Contract Test Tenant',
            'slug' => 'contract-test-fraud-settings',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Contract Test Shop',
            'legal_name' => 'Contract Test Shop LLC',
            'tax_id' => 'TAX999',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');

        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Contract Cashier',
            'email' => 'cashier@contract-fraud-test.local',
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

    public function test_response_is_200(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/fraud-settings');

        $response->assertOk();
    }

    public function test_data_keys_are_camel_case(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/fraud-settings');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertArrayHasKey('companyId', $data);
        $this->assertArrayHasKey('cashVarianceOverSoft', $data);
        $this->assertArrayHasKey('cashVarianceOverHard', $data);
        $this->assertArrayHasKey('cashVarianceUnderSoft', $data);
        $this->assertArrayHasKey('cashVarianceUnderHard', $data);
        $this->assertArrayHasKey('requireBlindCashCount', $data);
        $this->assertArrayHasKey('requireManagerPinAboveHard', $data);
        $this->assertArrayHasKey('cashVarianceEmailSeverity', $data);
    }

    public function test_data_keys_are_not_snake_case(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/fraud-settings');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertArrayNotHasKey('company_id', $data);
        $this->assertArrayNotHasKey('cash_variance_over_soft', $data);
        $this->assertArrayNotHasKey('cash_variance_over_hard', $data);
        $this->assertArrayNotHasKey('cash_variance_under_soft', $data);
        $this->assertArrayNotHasKey('cash_variance_under_hard', $data);
        $this->assertArrayNotHasKey('require_blind_cash_count', $data);
        $this->assertArrayNotHasKey('require_manager_pin_above_hard', $data);
        $this->assertArrayNotHasKey('cash_variance_email_severity', $data);
    }
}
