<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\Enums\ProcurementPreset;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class ProcurementPolicyApiTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $otherCompany;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Policy API Tenant',
            'slug' => 'policy-api-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Policy API Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Policy API Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Policy Admin',
            'email' => 'policy-admin@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->admin->assignRole('admin');
        $this->attachMembership($this->admin, $this->company, MembershipRole::Owner);

        $this->viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Policy Viewer',
            'email' => 'policy-viewer@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->viewer->assignRole('viewer');
        $this->attachMembership($this->viewer, $this->company, MembershipRole::Viewer);
    }

    public function test_get_returns_current_company_policy_and_preset(): void
    {
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'preset' => ProcurementPreset::Standard,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);

        $response = $this->actingAs($this->viewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/procurement-policies');

        $response->assertOk()
            ->assertJsonPath('data.company_id', $this->company->id)
            ->assertJsonPath('data.preset', 'standard')
            ->assertJsonPath('data.match_mode', 'three_way')
            ->assertJsonPath('data.match_enforcement', 'warn');
    }

    public function test_put_preset_applies_bundle_and_scopes_to_current_company(): void
    {
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->otherCompany->id,
            'preset' => ProcurementPreset::Complet,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Block,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/v1/procurement-policies', [
                'preset' => 'leger',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.preset', 'leger')
            ->assertJsonPath('data.bill_control_mode', 'received')
            ->assertJsonPath('data.match_mode', 'two_way')
            ->assertJsonPath('data.match_enforcement', 'warn')
            ->assertJsonPath('data.variance_tolerance_percent', '2.00')
            ->assertJsonPath('data.variance_tolerance_max_amount', '1.000');

        $this->assertDatabaseHas('procurement_policies', [
            'company_id' => $this->company->id,
            'preset' => 'leger',
            'match_mode' => 'two_way',
            'match_enforcement' => 'warn',
        ]);
        $this->assertDatabaseHas('procurement_policies', [
            'company_id' => $this->otherCompany->id,
            'preset' => 'complet',
            'match_enforcement' => 'block',
        ]);
    }

    public function test_put_raw_fields_clears_preset(): void
    {
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'preset' => ProcurementPreset::Standard,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/v1/procurement-policies', [
                'bill_control_mode' => 'received',
                'match_mode' => 'three_way',
                'match_enforcement' => 'block',
                'variance_tolerance_percent' => '3.50',
                'variance_tolerance_max_amount' => '4.250',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.preset', null)
            ->assertJsonPath('data.match_enforcement', 'block')
            ->assertJsonPath('data.variance_tolerance_percent', '3.50')
            ->assertJsonPath('data.variance_tolerance_max_amount', '4.250');

        $this->assertDatabaseHas('procurement_policies', [
            'company_id' => $this->company->id,
            'preset' => null,
            'match_enforcement' => 'block',
        ]);
    }

    public function test_update_requires_settings_update_permission(): void
    {
        $response = $this->actingAs($this->viewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/v1/procurement-policies', [
                'preset' => 'standard',
            ]);

        $response->assertForbidden();
    }

    public function test_update_rejects_preset_mixed_with_raw_fields(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/v1/procurement-policies', [
                'preset' => 'standard',
                'match_enforcement' => 'block',
            ]);

        $this->assertApiValidationErrors($response, ['preset']);
    }

    public function test_update_rejects_invalid_enum_and_tolerance_values(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/v1/procurement-policies', [
                'bill_control_mode' => 'ordered',
                'match_mode' => 'sideways',
                'match_enforcement' => 'stop',
                'variance_tolerance_percent' => '-1',
                'variance_tolerance_max_amount' => '-0.001',
            ]);

        $this->assertApiValidationErrors($response, [
            'bill_control_mode',
            'match_mode',
            'match_enforcement',
            'variance_tolerance_percent',
            'variance_tolerance_max_amount',
        ]);
    }

    private function attachMembership(User $user, Company $company, MembershipRole $role): void
    {
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => $role,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
    }
    public function test_applying_a_preset_preserves_hand_tuned_tolerances(): void
    {
        $policy = ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'preset' => ProcurementPreset::Standard,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '7.50',
            'variance_tolerance_max_amount' => '9.999',
        ]);

        $policy->applyPreset(\App\Modules\Procurement\Domain\Enums\ProcurementPreset::Leger)->save();
        $policy->refresh();

        $this->assertSame(0, bccomp('7.50', $policy->variance_tolerance_percent, 2));
        $this->assertSame(0, bccomp('9.999', $policy->variance_tolerance_max_amount, 3));
        $this->assertSame(\App\Modules\Procurement\Domain\Enums\MatchMode::TwoWay, $policy->match_mode);
        $this->assertSame(\App\Modules\Procurement\Domain\Enums\ProcurementPreset::Leger, $policy->preset);
    }
}
