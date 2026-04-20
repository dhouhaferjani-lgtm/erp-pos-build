<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Application\Services\WeeklyHoursService;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WeeklyHoursServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => 'Europe/Paris',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function makeProfile(string $costRate = '25.000', string $billRate = '60.000', string $currency = 'EUR'): TechnicianProfile
    {
        return TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'hourly_cost_rate' => $costRate,
            'hourly_billing_rate' => $billRate,
            'currency' => $currency,
        ]);
    }

    private function addEntry(
        TechnicianProfile $profile,
        \DateTimeImmutable $start,
        int $durationMin,
        TimeEntryType $type,
        ?string $workOrderId = null,
    ): void {
        $end = $start->modify(sprintf('+%d minutes', $durationMin));
        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $profile->id,
            'started_at' => $start,
            'ended_at' => $end,
            'duration_minutes' => $durationMin,
            'entry_type' => $type->value,
            'work_order_id' => $workOrderId,
            'source' => TimeEntrySource::Event->value,
        ]);
    }

    public function test_aggregates_minutes_by_entry_type_and_per_work_order(): void
    {
        $profile = $this->makeProfile();
        $weekStart = new \DateTimeImmutable('2026-04-20T00:00:00', new \DateTimeZone('Europe/Paris')); // Mon
        $wo1 = (string) Str::uuid();
        $wo2 = (string) Str::uuid();

        $this->addEntry($profile, $weekStart->modify('+8 hours'), 120, TimeEntryType::WorkOrder, $wo1);  // Mon 10:00, 2h
        $this->addEntry($profile, $weekStart->modify('+10 hours'), 180, TimeEntryType::WorkOrder, $wo1); // Mon 12:00, 3h
        $this->addEntry($profile, $weekStart->modify('+1 day'), 90, TimeEntryType::WorkOrder, $wo2);      // Tue 00:00, 1.5h
        $this->addEntry($profile, $weekStart->modify('+1 day +2 hours'), 30, TimeEntryType::Break);       // Tue 02:00, 30m

        $service = $this->app->make(WeeklyHoursService::class);
        $summary = $service->computeWeek($profile->id, $weekStart);

        $this->assertSame(120 + 180 + 90 + 30, $summary->total_minutes);
        $this->assertSame(390, $summary->minutes_by_entry_type[TimeEntryType::WorkOrder->value] ?? null);
        $this->assertSame(30, $summary->minutes_by_entry_type[TimeEntryType::Break->value] ?? null);

        $breakdown = $summary->work_order_breakdown;
        $this->assertCount(2, $breakdown);
        $byWo = [];
        foreach ($breakdown as $line) {
            $byWo[$line['work_order_id']] = $line['minutes'];
        }
        $this->assertSame(300, $byWo[$wo1]);
        $this->assertSame(90, $byWo[$wo2]);
    }

    public function test_estimated_amounts_use_bcformat_scale(): void
    {
        $profile = $this->makeProfile(costRate: '25.000', billRate: '60.000', currency: 'EUR');
        $weekStart = new \DateTimeImmutable('2026-04-20T00:00:00', new \DateTimeZone('Europe/Paris'));
        $this->addEntry($profile, $weekStart->modify('+8 hours'), 120, TimeEntryType::WorkOrder, (string) Str::uuid()); // 2h

        $summary = $this->app->make(WeeklyHoursService::class)->computeWeek($profile->id, $weekStart);

        // 2h * 60 EUR/h = 120.00 (EUR scale = 2)
        $this->assertSame('120.00', $summary->estimated_billable_amount);
        // 2h * 25 EUR/h = 50.00
        $this->assertSame('50.00', $summary->estimated_cost_amount);
    }

    public function test_overtime_minutes_computed_against_weekly_schedule(): void
    {
        $profile = $this->makeProfile();
        // Default factory schedule = 5 days * 8 hours = 2400 minutes.
        $weekStart = new \DateTimeImmutable('2026-04-20T00:00:00', new \DateTimeZone('Europe/Paris'));
        // Log 2500 minutes (100 over).
        $this->addEntry($profile, $weekStart->modify('+8 hours'), 2500, TimeEntryType::WorkOrder, (string) Str::uuid());

        $summary = $this->app->make(WeeklyHoursService::class)->computeWeek($profile->id, $weekStart);

        $this->assertSame(100, $summary->overtime_minutes);
    }

    public function test_zero_entries_yields_zero_totals_and_zero_amounts(): void
    {
        $profile = $this->makeProfile();
        $weekStart = new \DateTimeImmutable('2026-04-20T00:00:00', new \DateTimeZone('Europe/Paris'));

        $summary = $this->app->make(WeeklyHoursService::class)->computeWeek($profile->id, $weekStart);

        $this->assertSame(0, $summary->total_minutes);
        $this->assertSame(0, $summary->overtime_minutes);
        $this->assertSame('0.00', $summary->estimated_billable_amount);
        $this->assertSame('0.00', $summary->estimated_cost_amount);
    }
}
