<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Queries\CapacityReportQuery;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CapacityReportQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deactivate every bay for the company except the ids supplied so
     * per-bay totals are deterministic across tests (the Appointment
     * factory spawns incidental bays).
     *
     * @param  list<string>  $keepBayIds
     */
    private function isolateBaysForCompany(string $companyId, array $keepBayIds): void
    {
        Bay::query()
            ->where('company_id', $companyId)
            ->whereNotIn('id', $keepBayIds)
            ->update(['is_active' => false]);
    }

    public function test_empty_month_has_zero_booked_minutes(): void
    {
        $bay = Bay::factory()->create();
        $this->isolateBaysForCompany($bay->company_id, [$bay->id]);
        /** @var CapacityReportQuery $query */
        $query = $this->app->make(CapacityReportQuery::class);

        // 2026-05-01 is a Friday — operating_hours for "fri" (default factory) is 08–12 + 13–17 = 480 minutes.
        $report = $query->forMonth($bay->company_id, new \DateTimeImmutable('2026-05-01'));

        $this->assertSame(['year' => 2026, 'month' => 5], $report['period']);
        $this->assertArrayHasKey($bay->id, $report['bays']);
        $this->assertSame(480, $report['bays'][$bay->id]['2026-05-01']['operating_minutes']);
        $this->assertSame(0, $report['bays'][$bay->id]['2026-05-01']['booked_minutes']);
        $this->assertSame(0, $report['bays'][$bay->id]['2026-05-01']['utilization_pct']);
    }

    public function test_counts_booked_minutes_and_utilization(): void
    {
        $bay = Bay::factory()->create();

        // Single 2-hour booking on Monday 2026-05-04.
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 12:00:00'),
        )->create(['company_id' => $bay->company_id]);
        $this->isolateBaysForCompany($bay->company_id, [$bay->id]);

        /** @var CapacityReportQuery $query */
        $query = $this->app->make(CapacityReportQuery::class);
        $report = $query->forMonth($bay->company_id, new \DateTimeImmutable('2026-05-01'));

        $monday = $report['bays'][$bay->id]['2026-05-04'];
        $this->assertSame(480, $monday['operating_minutes']);
        $this->assertSame(120, $monday['booked_minutes']);
        $this->assertSame(25, $monday['utilization_pct']); // 120 / 480 = 25%
    }

    public function test_weekend_days_have_zero_operating_minutes(): void
    {
        $bay = Bay::factory()->create();
        $this->isolateBaysForCompany($bay->company_id, [$bay->id]);

        /** @var CapacityReportQuery $query */
        $query = $this->app->make(CapacityReportQuery::class);
        $report = $query->forMonth($bay->company_id, new \DateTimeImmutable('2026-05-01'));

        // 2026-05-02 is Saturday, 2026-05-03 is Sunday — default factory has empty arrays.
        $this->assertSame(0, $report['bays'][$bay->id]['2026-05-02']['operating_minutes']);
        $this->assertSame(0, $report['bays'][$bay->id]['2026-05-03']['operating_minutes']);
        $this->assertSame(0, $report['bays'][$bay->id]['2026-05-02']['utilization_pct']);
    }

    public function test_cancelled_appointments_are_excluded_from_booked_minutes(): void
    {
        $bay = Bay::factory()->create();
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 12:00:00'),
        )->create([
            'company_id' => $bay->company_id,
            'status' => AppointmentStatus::Cancelled->value,
        ]);
        $this->isolateBaysForCompany($bay->company_id, [$bay->id]);

        /** @var CapacityReportQuery $query */
        $query = $this->app->make(CapacityReportQuery::class);
        $report = $query->forMonth($bay->company_id, new \DateTimeImmutable('2026-05-01'));

        $this->assertSame(0, $report['bays'][$bay->id]['2026-05-04']['booked_minutes']);
    }

    public function test_totals_aggregate_across_all_bays_per_date(): void
    {
        $bayA = Bay::factory()->create();
        $bayB = Bay::factory()->create([
            'tenant_id' => $bayA->tenant_id,
            'company_id' => $bayA->company_id,
            'location_id' => $bayA->location_id,
        ]);

        // 60 min on bay A + 30 min on bay B on the same Monday.
        Appointment::factory()->onBay(
            $bayA->id,
            new \DateTimeImmutable('2026-05-04 09:00:00'),
            new \DateTimeImmutable('2026-05-04 10:00:00'),
        )->create(['company_id' => $bayA->company_id]);
        Appointment::factory()->onBay(
            $bayB->id,
            new \DateTimeImmutable('2026-05-04 14:00:00'),
            new \DateTimeImmutable('2026-05-04 14:30:00'),
        )->create(['company_id' => $bayA->company_id]);
        $this->isolateBaysForCompany($bayA->company_id, [$bayA->id, $bayB->id]);

        /** @var CapacityReportQuery $query */
        $query = $this->app->make(CapacityReportQuery::class);
        $report = $query->forMonth($bayA->company_id, new \DateTimeImmutable('2026-05-01'));

        $totals = $report['totals']['2026-05-04'];
        $this->assertSame(960, $totals['operating_minutes']); // 480 * 2 bays
        $this->assertSame(90, $totals['booked_minutes']);     // 60 + 30
        $this->assertSame(9, $totals['utilization_pct']);     // 90 / 960 ≈ 9.38 → 9
    }
}
