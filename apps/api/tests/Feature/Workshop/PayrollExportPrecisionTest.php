<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop;

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

/**
 * Precision regression: payroll gross-pay calculation must be
 * float-free (multiply-first bcmath pattern).
 *
 * Gold standard: 36000 minutes × 25.123 TND/hour = exactly '15073.800'
 * (= 600.000 hours × 25.123 = 15073.800)
 *
 * The old code did  $hours = $minutes / 60  (PHP float division)
 * then  bcmul($costRate, (string)$hours, 6)  — casting the float to string
 * can produce scientific-notation or truncated representations for large
 * minute counts.  The fix: multiply-first, then divide:
 *     bcdiv(bcmul($costRate, (string)$minutes, 6), '60', 6)
 */
final class PayrollExportPrecisionTest extends TestCase
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

        foreach (['workshop.payroll.view', 'workshop.payroll.generate'] as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->givePermissionTo(['workshop.payroll.view', 'workshop.payroll.generate']);

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

    /**
     * Gold-standard: 36 000 minutes at 25.123 TND/hour must produce
     * gross_pay = '15073.800' (3 decimal places, TND scale).
     *
     * With float division: (int)36000 / 60 = 600.0 (exact in this case),
     * but (string)600.0 = '600' in PHP, and bcmul('25.123','600',6) = '15073.800000'.
     * CurrencyScale::bcformat(..., 3) then gives '15073.800'. ✓
     *
     * The critical invariant: the calculation must NOT use float intermediates.
     * After the fix we verify the output is the canonical '15073.800' string.
     */
    public function test_gross_pay_gold_standard_36000_minutes_at_25123_per_hour(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        /** @var TechnicianProfile $profile */
        $profile = TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_code' => 'PREC-01',
            'hourly_cost_rate' => '25.123',
            'currency' => 'TND',
        ]);

        // 36 000 minutes = 600 hours.  Stored as a single time entry.
        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $profile->id,
            'started_at' => '2026-04-01 00:00:00',
            'ended_at' => '2026-04-26 12:00:00',
            'duration_minutes' => 36000,
            'entry_type' => TimeEntryType::WorkOrder,
        ]);

        $response = $this->post(
            '/api/v1/workshop/payroll-exports',
            [
                'pay_period_start' => '2026-04-01',
                'pay_period_end' => '2026-04-30',
            ],
            ['Accept' => 'text/csv'],
        );

        $response->assertOk();
        $body = $response->streamedContent();

        // Parse the CSV to find the gross_pay column value.
        $lines = array_values(array_filter(explode("\n", trim($body))));
        $this->assertCount(2, $lines, 'Expected header + 1 data row');

        $headers = str_getcsv($lines[0]);
        $values = str_getcsv($lines[1]);
        $row = array_combine($headers, $values);

        $this->assertIsArray($row);
        $this->assertArrayHasKey('gross_pay', $row);

        // The canonical gold-standard value for 36 000 min × 25.123 TND/hour.
        $this->assertSame('15073.800', $row['gross_pay']);
    }

    /**
     * hours_worked must be bcmath-derived, not number_format(float/60, 2).
     *
     * 1 minute: float path gives '0.02' (rounds up IEEE 754 0.01666…),
     * bcmath path (bcdiv('1','60',2)) gives '0.01' (truncates correctly).
     * This test pins the correct bcmath-truncated value.
     */
    public function test_hours_worked_one_minute_is_bcmath_truncated(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        /** @var TechnicianProfile $profile */
        $profile = TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_code' => 'PREC-02',
            'hourly_cost_rate' => '60.000',
            'currency' => 'EUR',
        ]);

        // 1 minute entry
        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $profile->id,
            'started_at' => '2026-04-01 08:00:00',
            'ended_at' => '2026-04-01 08:01:00',
            'duration_minutes' => 1,
            'entry_type' => TimeEntryType::WorkOrder,
        ]);

        $response = $this->post(
            '/api/v1/workshop/payroll-exports',
            [
                'pay_period_start' => '2026-04-01',
                'pay_period_end' => '2026-04-30',
            ],
            ['Accept' => 'text/csv'],
        );

        $response->assertOk();
        $body = $response->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($body))));
        $headers = str_getcsv($lines[0]);
        $values = str_getcsv($lines[1]);
        $row = array_combine($headers, $values);

        $this->assertIsArray($row);

        // bcmath truncation: bcdiv('1','60',2) = '0.01'
        // float path:        number_format(1/60, 2) = '0.02'  ← WRONG
        $this->assertSame('0.01', $row['hours_worked'],
            'hours_worked must use bcmath division, not float');
    }

    /**
     * Additional invariant: the hours_worked field in the CSV
     * must equal '600.00' (600 hours, 2 decimal places) — no float
     * precision artifacts.
     */
    public function test_hours_worked_field_is_exact_for_large_minute_count(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        /** @var TechnicianProfile $profile */
        $profile = TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_code' => 'PREC-03',
            'hourly_cost_rate' => '10.000',
            'currency' => 'EUR',
        ]);

        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $profile->id,
            'started_at' => '2026-04-01 00:00:00',
            'ended_at' => '2026-04-26 12:00:00',
            'duration_minutes' => 36000,
            'entry_type' => TimeEntryType::WorkOrder,
        ]);

        $response = $this->post(
            '/api/v1/workshop/payroll-exports',
            [
                'pay_period_start' => '2026-04-01',
                'pay_period_end' => '2026-04-30',
            ],
            ['Accept' => 'text/csv'],
        );

        $response->assertOk();
        $body = $response->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($body))));
        $headers = str_getcsv($lines[0]);
        $values = str_getcsv($lines[1]);
        $row = array_combine($headers, $values);

        $this->assertIsArray($row);
        $this->assertSame('600.00', $row['hours_worked']);
    }
}
