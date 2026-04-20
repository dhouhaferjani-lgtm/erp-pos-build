<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use App\Modules\Scheduling\Domain\ValueObjects\AvailabilityWindow;
use App\Modules\Scheduling\Domain\ValueObjects\ConflictDetail;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Scheduling value object shapes.
 *
 * - AvailabilityWindow: tstzrange-like `[start, end)` with a bay_id and
 *   optional technician_id (half-open semantics match the GiST exclusion
 *   `tstzrange(scheduled_start, scheduled_end, '[)')`).
 * - ConflictDetail: surfaced on 409 from storefront POST /appointments
 *   and on 422 from staff create — carries a conflict_type enum string,
 *   the offending appointment ids, and a human message.
 */
final class ValueObjectsTest extends TestCase
{
    public function test_availability_window_exposes_resource_and_window(): void
    {
        $win = new AvailabilityWindow(
            resource_type: AvailabilityWindow::TYPE_BAY,
            resource_id: 'bay-1',
            starts_at: new DateTimeImmutable('2026-05-01 10:00:00'),
            ends_at: new DateTimeImmutable('2026-05-01 11:00:00'),
        );

        $this->assertSame('bay', $win->resource_type);
        $this->assertSame('bay-1', $win->resource_id);
        $this->assertSame('2026-05-01 10:00:00', $win->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-01 11:00:00', $win->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_availability_window_duration_minutes_is_end_minus_start(): void
    {
        $win = new AvailabilityWindow(
            resource_type: AvailabilityWindow::TYPE_BAY,
            resource_id: 'bay-1',
            starts_at: new DateTimeImmutable('2026-05-01 10:00:00'),
            ends_at: new DateTimeImmutable('2026-05-01 11:30:00'),
        );

        $this->assertSame(90, $win->durationMinutes());
    }

    public function test_availability_window_rejects_end_equal_or_before_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AvailabilityWindow(
            resource_type: AvailabilityWindow::TYPE_BAY,
            resource_id: 'bay-1',
            starts_at: new DateTimeImmutable('2026-05-01 11:00:00'),
            ends_at: new DateTimeImmutable('2026-05-01 11:00:00'),
        );
    }

    public function test_availability_window_rejects_invalid_resource_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AvailabilityWindow(
            resource_type: 'rocket', // invalid
            resource_id: 'r-1',
            starts_at: new DateTimeImmutable('2026-05-01 10:00:00'),
            ends_at: new DateTimeImmutable('2026-05-01 11:00:00'),
        );
    }

    public function test_availability_window_half_open_overlap_touching_boundary_is_false(): void
    {
        $a = new AvailabilityWindow(
            resource_type: AvailabilityWindow::TYPE_BAY,
            resource_id: 'bay-1',
            starts_at: new DateTimeImmutable('10:00'),
            ends_at: new DateTimeImmutable('11:00'),
        );
        $b = new AvailabilityWindow(
            resource_type: AvailabilityWindow::TYPE_BAY,
            resource_id: 'bay-1',
            starts_at: new DateTimeImmutable('11:00'),
            ends_at: new DateTimeImmutable('12:00'),
        );
        $this->assertFalse($a->overlaps($b));
        $this->assertFalse($b->overlaps($a));
    }

    public function test_availability_window_inner_overlap_is_true(): void
    {
        $a = new AvailabilityWindow(
            resource_type: AvailabilityWindow::TYPE_BAY,
            resource_id: 'bay-1',
            starts_at: new DateTimeImmutable('10:00'),
            ends_at: new DateTimeImmutable('11:00'),
        );
        $b = new AvailabilityWindow(
            resource_type: AvailabilityWindow::TYPE_BAY,
            resource_id: 'bay-1',
            starts_at: new DateTimeImmutable('10:30'),
            ends_at: new DateTimeImmutable('11:30'),
        );
        $this->assertTrue($a->overlaps($b));
        $this->assertTrue($b->overlaps($a));
    }

    public function test_conflict_detail_overlap_factory(): void
    {
        $conflict = ConflictDetail::overlap(
            conflicting_appointment_ids: ['appt-1', 'appt-2'],
            message: 'Bay is already booked in this window.',
        );

        $this->assertSame(ConflictDetail::TYPE_OVERLAP, $conflict->conflict_type);
        $this->assertSame(['appt-1', 'appt-2'], $conflict->conflicting_appointment_ids);
        $this->assertSame('Bay is already booked in this window.', $conflict->message);
    }

    public function test_conflict_detail_out_of_hours_factory(): void
    {
        $conflict = ConflictDetail::outOfHours('Bay closed at 17:00.');
        $this->assertSame(ConflictDetail::TYPE_OUT_OF_HOURS, $conflict->conflict_type);
        $this->assertSame([], $conflict->conflicting_appointment_ids);
    }

    public function test_conflict_detail_technician_busy_factory(): void
    {
        $conflict = ConflictDetail::technicianBusy(
            conflicting_appointment_ids: ['appt-9'],
            message: 'Technician has overlapping work.',
        );
        $this->assertSame(ConflictDetail::TYPE_TECHNICIAN_BUSY, $conflict->conflict_type);
    }

    public function test_conflict_detail_type_constants_are_stable(): void
    {
        $this->assertSame('overlap', ConflictDetail::TYPE_OVERLAP);
        $this->assertSame('out_of_hours', ConflictDetail::TYPE_OUT_OF_HOURS);
        $this->assertSame('technician_busy', ConflictDetail::TYPE_TECHNICIAN_BUSY);
    }
}
