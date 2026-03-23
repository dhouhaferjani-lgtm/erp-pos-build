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

class VatReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant Reports',
            'slug' => 'test-tenant-vat-reports',
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
            'name' => 'Test Company Reports',
            'legal_name' => 'Test Company Reports SARL',
            'tax_id' => '9876543XYZ',
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
            'name' => 'Test Accountant',
            'email' => 'accountant-vat@example.com',
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

    public function test_summary_returns_vat_breakdown(): void
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
            'total_output_vat' => '1900.000',
            'total_input_vat' => '500.000',
            'net_vat' => '1400.000',
            'amount_payable' => '1400.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vat/reports/{$period->id}/summary");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'output_vat',
                    'input_vat',
                    'net_vat',
                    'credit_brought_forward',
                    'credit_carried_forward',
                    'amount_payable',
                ],
            ]);
    }

    public function test_ad_hoc_summary_with_date_range(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/vat/reports/summary?'.http_build_query([
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'output_vat',
                    'input_vat',
                    'net_vat',
                    'credit_brought_forward',
                    'credit_carried_forward',
                    'amount_payable',
                ],
            ]);
    }

    public function test_export_returns_streamed_response(): void
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
            'total_output_vat' => '1900.000',
            'total_input_vat' => '500.000',
            'net_vat' => '1400.000',
            'amount_payable' => '1400.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/vat/reports/{$period->id}/export/csv");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
        $response->assertHeader('content-disposition');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Rate', $content);
    }

    public function test_period_summary_returns_snapshot_for_closed_period(): void
    {
        $period = VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => 'February 2026',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
            'total_output_vat' => '10000.000',
            'total_input_vat' => '6000.000',
            'net_vat' => '4000.000',
            'amount_payable' => '4000.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vat/reports/{$period->id}/summary");

        $response->assertOk();

        $data = $response->json('data');
        $this->assertEquals('4000.000', $data['net_vat']);
        $this->assertEquals('4000.000', $data['amount_payable']);
    }

    public function test_export_formats_returns_country_specific_formats(): void
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
            'total_output_vat' => '1900.000',
            'total_input_vat' => '500.000',
            'net_vat' => '1400.000',
            'amount_payable' => '1400.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/vat/reports/{$period->id}/export-formats");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'format',
                        'label',
                    ],
                ],
            ]);
    }
}
