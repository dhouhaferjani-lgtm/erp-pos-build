<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VatPeriodControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-vat',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company TN',
            'legal_name' => 'Test Company TN SARL',
            'tax_id' => '1234567ABC',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Manager',
            'email' => 'manager-vat@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_list_periods_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/vat/periods');

        $response->assertStatus(401);
    }

    public function test_list_periods_returns_periods_for_company(): void
    {
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Open,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vat/periods');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'company_id',
                        'country_code',
                        'period_type',
                        'label',
                        'period_start',
                        'period_end',
                        'status',
                    ],
                ],
            ]);
    }

    public function test_generate_periods_creates_monthly_for_tunisia(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/vat/periods/generate', [
                'year' => 2026,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseCount('vat_periods', 12);

        $periods = VatPeriod::where('company_id', $this->company->id)
            ->where('country_code', 'TN')
            ->orderBy('period_start')
            ->get();

        $this->assertCount(12, $periods);
        $this->assertSame('2026-01-01', $periods->first()->period_start->toDateString());
        $this->assertSame('2026-12-31', $periods->last()->period_end->toDateString());
    }

    public function test_close_period_snapshots_data(): void
    {
        $period = VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Open,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/vat/periods/{$period->id}/close", [
                'notes' => 'Closing January',
            ]);

        $response->assertStatus(200);

        $period->refresh();
        $this->assertSame(VatPeriodStatus::Closed, $period->status);
        $this->assertNotNull($period->closed_at);
    }

    public function test_cannot_reopen_filed_period(): void
    {
        $period = VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Filed,
            'closed_at' => now()->subDay(),
            'filed_at' => now(),
            'total_output_vat' => '1000.000',
            'total_input_vat' => '200.000',
            'net_vat' => '800.000',
            'amount_payable' => '800.000',
            'filing_reference' => 'REF-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/vat/periods/{$period->id}/reopen");

        $response->assertStatus(422);
    }

    public function test_cannot_file_open_period(): void
    {
        $period = VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Open,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/vat/periods/{$period->id}/file");

        $response->assertStatus(422);
    }

    public function test_cannot_reopen_period_when_successor_is_closed(): void
    {
        $period = VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
            'total_output_vat' => '1000.000',
            'total_input_vat' => '200.000',
            'net_vat' => '800.000',
            'amount_payable' => '800.000',
        ]);

        // Create a successor period that is also closed
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'February 2026',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
            'total_output_vat' => '1200.000',
            'total_input_vat' => '300.000',
            'net_vat' => '900.000',
            'amount_payable' => '900.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/vat/periods/{$period->id}/reopen");

        $response->assertStatus(422);
    }
}
