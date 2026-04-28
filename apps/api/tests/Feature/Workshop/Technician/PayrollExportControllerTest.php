<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PayrollExportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([
            'workshop.payroll.view',
            'workshop.payroll.generate',
        ] as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->givePermissionTo([
            'workshop.payroll.view',
            'workshop.payroll.generate',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Sanctum::actingAs($this->manager);
    }

    private function makeProfile(string $code, string $hourlyCost): TechnicianProfile
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        return TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_code' => $code,
            'hourly_cost_rate' => $hourlyCost,
        ]);
    }

    public function test_index_returns_empty_list_v1(): void
    {
        // Stateless v1 — no persisted exports. Index is always empty.
        $response = $this->getJson('/api/v1/workshop/payroll-exports');

        $response->assertOk();
        $response->assertExactJson(['data' => []]);
    }

    public function test_generate_returns_csv_for_all_techs_in_period(): void
    {
        $profile = $this->makeProfile('TECH-01', '18.000');

        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $profile->id,
            'started_at' => '2026-04-01 09:00:00',
            'ended_at' => '2026-04-01 13:00:00',
            'duration_minutes' => 240,
            'entry_type' => TimeEntryType::WorkOrder,
        ]);

        $response = $this->post(
            '/api/v1/workshop/payroll-exports',
            [
                'pay_period_start' => '2026-04-01',
                'pay_period_end' => '2026-04-30',
            ],
            ['Accept' => 'text/csv']
        );

        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        // streamedContent() is declared `string` so the prior assertIsString
        // call always evaluated to true (PHPStan method.alreadyNarrowedType).
        $body = $response->streamedContent();
        $this->assertStringContainsString('TECH-01', $body);
        $this->assertStringContainsString('4.00', $body); // 240 min = 4h
    }

    public function test_generate_filters_to_selected_technicians(): void
    {
        $profileA = $this->makeProfile('TECH-A', '20.000');
        $profileB = $this->makeProfile('TECH-B', '25.000');

        foreach ([$profileA, $profileB] as $p) {
            TechnicianTimeEntry::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'technician_profile_id' => $p->id,
                'started_at' => '2026-04-02 09:00:00',
                'ended_at' => '2026-04-02 12:00:00',
                'duration_minutes' => 180,
                'entry_type' => TimeEntryType::WorkOrder,
            ]);
        }

        $response = $this->post(
            '/api/v1/workshop/payroll-exports',
            [
                'pay_period_start' => '2026-04-01',
                'pay_period_end' => '2026-04-30',
                'technician_ids' => [$profileA->id],
            ]
        );

        $response->assertOk();
        $body = (string) $response->streamedContent();
        $this->assertStringContainsString('TECH-A', $body);
        $this->assertStringNotContainsString('TECH-B', $body);
    }

    public function test_generate_on_empty_period_returns_valid_empty_csv(): void
    {
        $response = $this->post(
            '/api/v1/workshop/payroll-exports',
            [
                'pay_period_start' => '2026-04-01',
                'pay_period_end' => '2026-04-30',
            ]
        );

        $response->assertOk();
        $body = (string) $response->streamedContent();
        // Header row still present even on empty results.
        $this->assertStringContainsString('employee_code', $body);
        $lines = array_filter(explode("\n", trim($body)));
        $this->assertCount(1, $lines);
    }

    public function test_generate_denied_without_permission(): void
    {
        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $viewer->givePermissionTo('workshop.payroll.view');
        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);
        Sanctum::actingAs($viewer);

        $response = $this->postJson(
            '/api/v1/workshop/payroll-exports',
            [
                'pay_period_start' => '2026-04-01',
                'pay_period_end' => '2026-04-30',
            ]
        );

        $response->assertStatus(403);
    }
}
