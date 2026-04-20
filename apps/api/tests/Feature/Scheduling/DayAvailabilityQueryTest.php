<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Queries\DayAvailabilityQuery;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DayAvailabilityQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_date_availability_and_booked(): void
    {
        $bay = Bay::factory()->create([
            'operating_hours' => [
                'mon' => [['start' => '08:00', 'end' => '17:00']],
            ],
        ]);
        $appt = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 11:00:00'),
        )->create(['company_id' => $bay->company_id]);

        $query = $this->app->make(DayAvailabilityQuery::class);

        $result = $query->run($bay->company_id, new \DateTimeImmutable('2026-05-04'));

        $this->assertSame('2026-05-04', $result['date']);
        $this->assertArrayHasKey($bay->id, $result['availability']);
        $this->assertCount(2, $result['availability'][$bay->id]); // Split around 10-11
        $this->assertArrayHasKey($bay->id, $result['booked']);
        $this->assertCount(1, $result['booked'][$bay->id]);
        $this->assertSame($appt->id, $result['booked'][$bay->id][0]['appointment_id']);
    }
}
