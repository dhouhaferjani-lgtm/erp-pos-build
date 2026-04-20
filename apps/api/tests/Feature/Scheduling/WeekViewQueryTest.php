<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Queries\WeekViewQuery;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WeekViewQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_seven_keyed_days_in_chronological_order(): void
    {
        $bay = Bay::factory()->create();
        /** @var WeekViewQuery $query */
        $query = $this->app->make(WeekViewQuery::class);

        $result = $query->run($bay->company_id, new \DateTimeImmutable('2026-05-04'));

        $keys = array_keys($result);
        $this->assertCount(7, $keys);
        $this->assertSame('2026-05-04', $keys[0]);
        $this->assertSame('2026-05-10', $keys[6]);
    }

    public function test_appointments_are_bucketed_by_start_date(): void
    {
        $bay = Bay::factory()->create();
        $mon = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 11:00:00'),
        )->create(['company_id' => $bay->company_id]);
        $wed = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-06 14:00:00'),
            new \DateTimeImmutable('2026-05-06 15:30:00'),
        )->create(['company_id' => $bay->company_id]);

        /** @var WeekViewQuery $query */
        $query = $this->app->make(WeekViewQuery::class);

        $result = $query->run($bay->company_id, new \DateTimeImmutable('2026-05-04'));

        $this->assertCount(1, $result['2026-05-04']);
        $this->assertSame($mon->id, $result['2026-05-04'][0]['appointment_id']);
        $this->assertCount(1, $result['2026-05-06']);
        $this->assertSame($wed->id, $result['2026-05-06'][0]['appointment_id']);
        $this->assertCount(0, $result['2026-05-05']);
    }

    public function test_cancelled_and_no_show_are_excluded(): void
    {
        $bay = Bay::factory()->create();
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 11:00:00'),
        )->create([
            'company_id' => $bay->company_id,
            'status' => AppointmentStatus::Cancelled->value,
        ]);
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 12:00:00'),
            new \DateTimeImmutable('2026-05-04 13:00:00'),
        )->create([
            'company_id' => $bay->company_id,
            'status' => AppointmentStatus::NoShow->value,
        ]);
        $active = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 14:00:00'),
            new \DateTimeImmutable('2026-05-04 15:00:00'),
        )->create(['company_id' => $bay->company_id]);

        /** @var WeekViewQuery $query */
        $query = $this->app->make(WeekViewQuery::class);

        $result = $query->run($bay->company_id, new \DateTimeImmutable('2026-05-04'));

        $this->assertCount(1, $result['2026-05-04']);
        $this->assertSame($active->id, $result['2026-05-04'][0]['appointment_id']);
    }

    public function test_appointments_within_day_are_sorted_by_start(): void
    {
        $bay = Bay::factory()->create();
        $late = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 14:00:00'),
            new \DateTimeImmutable('2026-05-04 15:00:00'),
        )->create(['company_id' => $bay->company_id]);
        $early = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 09:00:00'),
            new \DateTimeImmutable('2026-05-04 10:00:00'),
        )->create(['company_id' => $bay->company_id]);

        /** @var WeekViewQuery $query */
        $query = $this->app->make(WeekViewQuery::class);

        $result = $query->run($bay->company_id, new \DateTimeImmutable('2026-05-04'));

        $this->assertSame($early->id, $result['2026-05-04'][0]['appointment_id']);
        $this->assertSame($late->id, $result['2026-05-04'][1]['appointment_id']);
    }
}
