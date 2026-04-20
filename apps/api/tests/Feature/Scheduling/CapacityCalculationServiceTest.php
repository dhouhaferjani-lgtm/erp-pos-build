<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Services\CapacityCalculationService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilityWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Capacity calculation service tests.
 *
 * `availabilityForDay` returns, per active bay, the list of free time-windows
 * given operating_hours - scheduled appointments (cancelled/no_show excluded).
 *
 * Scenarios covered:
 *  - Fully-booked day → empty window list
 *  - Partial availability (one appt in the middle) → two bracketing windows
 *  - Out-of-hours appt beyond operating_hours is ignored for subtraction
 *  - Cancelled appt releases its slot → full window is still available
 */
final class CapacityCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CapacityCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CapacityCalculationService::class);
    }

    public function test_empty_day_returns_full_operating_window(): void
    {
        $bay = $this->bayWithHours('08:00', '17:00');
        $date = new \DateTimeImmutable('2026-05-04'); // Monday

        $windows = $this->service->availabilityForDay($bay->company_id, $date);

        $this->assertArrayHasKey($bay->id, $windows);
        $this->assertCount(1, $windows[$bay->id]);
        $this->assertEqualsDate($windows[$bay->id][0]->starts_at, '2026-05-04 08:00:00');
        $this->assertEqualsDate($windows[$bay->id][0]->ends_at, '2026-05-04 17:00:00');
    }

    public function test_fully_booked_day_returns_no_windows(): void
    {
        $bay = $this->bayWithHours('10:00', '12:00');
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 12:00:00'),
        )->create(['company_id' => $bay->company_id]);

        $windows = $this->service->availabilityForDay(
            $bay->company_id,
            new \DateTimeImmutable('2026-05-04'),
        );

        $this->assertSame([], $windows[$bay->id]);
    }

    public function test_partial_availability_splits_into_two_windows(): void
    {
        $bay = $this->bayWithHours('08:00', '17:00');
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 12:00:00'),
        )->create(['company_id' => $bay->company_id]);

        $windows = $this->service->availabilityForDay(
            $bay->company_id,
            new \DateTimeImmutable('2026-05-04'),
        );

        $this->assertCount(2, $windows[$bay->id]);
        $this->assertEqualsDate($windows[$bay->id][0]->starts_at, '2026-05-04 08:00:00');
        $this->assertEqualsDate($windows[$bay->id][0]->ends_at, '2026-05-04 10:00:00');
        $this->assertEqualsDate($windows[$bay->id][1]->starts_at, '2026-05-04 12:00:00');
        $this->assertEqualsDate($windows[$bay->id][1]->ends_at, '2026-05-04 17:00:00');
    }

    public function test_cancelled_appointment_frees_its_slot(): void
    {
        $bay = $this->bayWithHours('10:00', '12:00');
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 12:00:00'),
        )->create([
            'company_id' => $bay->company_id,
            'status' => AppointmentStatus::Cancelled->value,
        ]);

        $windows = $this->service->availabilityForDay(
            $bay->company_id,
            new \DateTimeImmutable('2026-05-04'),
        );

        $this->assertCount(1, $windows[$bay->id]);
    }

    public function test_out_of_hours_appointment_is_ignored_for_subtraction(): void
    {
        $bay = $this->bayWithHours('10:00', '12:00');
        // Appointment fully before hours — should not clip the operating window.
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 06:00:00'),
            new \DateTimeImmutable('2026-05-04 07:00:00'),
        )->create(['company_id' => $bay->company_id]);

        $windows = $this->service->availabilityForDay(
            $bay->company_id,
            new \DateTimeImmutable('2026-05-04'),
        );

        $this->assertCount(1, $windows[$bay->id]);
        $this->assertEqualsDate($windows[$bay->id][0]->starts_at, '2026-05-04 10:00:00');
        $this->assertEqualsDate($windows[$bay->id][0]->ends_at, '2026-05-04 12:00:00');
    }

    public function test_inactive_bay_is_not_included(): void
    {
        $active = $this->bayWithHours('08:00', '17:00');
        $inactive = $this->bayWithHours('08:00', '17:00', isActive: false, tenantId: $active->tenant_id, companyId: $active->company_id, locationId: $active->location_id);

        $windows = $this->service->availabilityForDay(
            $active->company_id,
            new \DateTimeImmutable('2026-05-04'),
        );

        $this->assertArrayHasKey($active->id, $windows);
        $this->assertArrayNotHasKey($inactive->id, $windows);
    }

    private function bayWithHours(
        string $openHhmm,
        string $closeHhmm,
        bool $isActive = true,
        ?string $tenantId = null,
        ?string $companyId = null,
        ?string $locationId = null,
    ): Bay {
        $fullSchedule = [
            'mon' => [['start' => $openHhmm, 'end' => $closeHhmm]],
            'tue' => [['start' => $openHhmm, 'end' => $closeHhmm]],
            'wed' => [['start' => $openHhmm, 'end' => $closeHhmm]],
            'thu' => [['start' => $openHhmm, 'end' => $closeHhmm]],
            'fri' => [['start' => $openHhmm, 'end' => $closeHhmm]],
            'sat' => [['start' => $openHhmm, 'end' => $closeHhmm]],
            'sun' => [['start' => $openHhmm, 'end' => $closeHhmm]],
        ];

        $attrs = [
            'operating_hours' => $fullSchedule,
            'is_active' => $isActive,
        ];
        if ($tenantId !== null) {
            $attrs['tenant_id'] = $tenantId;
        }
        if ($companyId !== null) {
            $attrs['company_id'] = $companyId;
        }
        if ($locationId !== null) {
            $attrs['location_id'] = $locationId;
        }

        return Bay::factory()->create($attrs);
    }

    private function assertEqualsDate(\DateTimeImmutable $actual, string $expected): void
    {
        $this->assertSame($expected, $actual->format('Y-m-d H:i:s'));
    }

    /** @phpstan-assert AvailabilityWindow $window */
    public function assertIsAvailabilityWindow(mixed $window): void
    {
        $this->assertInstanceOf(AvailabilityWindow::class, $window);
    }
}
