<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Scheduling\Application\Queries\MonthSummaryQuery;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MonthSummaryQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_per_day_excluding_cancelled(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        Appointment::factory()->count(2)->create([
            'company_id' => $company->id,
            'scheduled_start' => new \DateTimeImmutable('2026-05-04 10:00:00'),
            'scheduled_end' => new \DateTimeImmutable('2026-05-04 11:00:00'),
        ]);
        Appointment::factory()->cancelled()->create([
            'company_id' => $company->id,
            'scheduled_start' => new \DateTimeImmutable('2026-05-04 12:00:00'),
            'scheduled_end' => new \DateTimeImmutable('2026-05-04 13:00:00'),
            'status' => AppointmentStatus::Cancelled->value,
        ]);
        Appointment::factory()->create([
            'company_id' => $company->id,
            'scheduled_start' => new \DateTimeImmutable('2026-05-10 09:00:00'),
            'scheduled_end' => new \DateTimeImmutable('2026-05-10 10:30:00'),
        ]);

        $query = $this->app->make(MonthSummaryQuery::class);
        $summary = $query->run($company->id, 2026, 5);

        $this->assertArrayHasKey('2026-05-04', $summary);
        $this->assertSame(2, $summary['2026-05-04']['appointment_count']);
        $this->assertSame(120, $summary['2026-05-04']['booked_minutes']); // 2 × 60

        $this->assertArrayHasKey('2026-05-10', $summary);
        $this->assertSame(1, $summary['2026-05-10']['appointment_count']);
        $this->assertSame(90, $summary['2026-05-10']['booked_minutes']);
    }

    public function test_summary_returns_empty_array_for_month_with_no_appointments(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $query = $this->app->make(MonthSummaryQuery::class);
        $summary = $query->run($company->id, 2030, 1);

        $this->assertSame([], $summary);
    }
}
