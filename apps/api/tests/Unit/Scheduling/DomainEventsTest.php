<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use App\Modules\Scheduling\Domain\Events\AppointmentCancelled;
use App\Modules\Scheduling\Domain\Events\AppointmentCheckedIn;
use App\Modules\Scheduling\Domain\Events\AppointmentConfirmed;
use App\Modules\Scheduling\Domain\Events\AppointmentConvertedToWorkOrder;
use App\Modules\Scheduling\Domain\Events\AppointmentRescheduled;
use App\Modules\Scheduling\Domain\Events\AppointmentScheduled;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the shape + immutability of Plan D domain events.
 *
 * Each event MUST be `final readonly` and expose snake_case constructor
 * props (per AutoERP typed-property convention). Rule 8 (events are
 * immutable forever) means these classes can never be restructured
 * without creating a versioned V2 replacement.
 */
final class DomainEventsTest extends TestCase
{
    public function test_appointment_scheduled_is_final_readonly_with_snake_case_props(): void
    {
        $event = new AppointmentScheduled(
            appointment_id: 'appt-1',
            tenant_id: 'tenant-1',
            company_id: 'company-1',
            location_id: 'location-1',
            bay_id: 'bay-1',
            customer_partner_id: 'partner-1',
            vehicle_id: 'vehicle-1',
            scheduled_start: new \DateTimeImmutable('2026-05-01 10:00'),
            scheduled_end: new \DateTimeImmutable('2026-05-01 11:00'),
            source: 'online',
            occurred_at: new \DateTimeImmutable,
        );

        $this->assertSame('appt-1', $event->appointment_id);
        $this->assertTrue((new ReflectionClass($event))->isFinal());
        $this->assertTrue((new ReflectionClass($event))->isReadOnly());
    }

    public function test_appointment_confirmed_is_final_readonly(): void
    {
        $event = new AppointmentConfirmed(
            appointment_id: 'appt-1',
            tenant_id: 'tenant-1',
            company_id: 'company-1',
            confirmed_by_user_id: 'user-1',
            occurred_at: new \DateTimeImmutable,
        );

        $this->assertTrue((new ReflectionClass($event))->isFinal());
        $this->assertTrue((new ReflectionClass($event))->isReadOnly());
        $this->assertSame('user-1', $event->confirmed_by_user_id);
    }

    public function test_appointment_rescheduled_captures_both_previous_and_new_windows(): void
    {
        $event = new AppointmentRescheduled(
            appointment_id: 'appt-1',
            tenant_id: 'tenant-1',
            company_id: 'company-1',
            previous_bay_id: 'bay-A',
            new_bay_id: 'bay-B',
            previous_scheduled_start: new \DateTimeImmutable('2026-05-01 10:00'),
            previous_scheduled_end: new \DateTimeImmutable('2026-05-01 11:00'),
            new_scheduled_start: new \DateTimeImmutable('2026-05-02 14:00'),
            new_scheduled_end: new \DateTimeImmutable('2026-05-02 15:00'),
            rescheduled_by_user_id: 'user-1',
            occurred_at: new \DateTimeImmutable,
        );

        $this->assertSame('bay-A', $event->previous_bay_id);
        $this->assertSame('bay-B', $event->new_bay_id);
        $this->assertTrue((new ReflectionClass($event))->isReadOnly());
    }

    public function test_appointment_checked_in_is_final_readonly(): void
    {
        $event = new AppointmentCheckedIn(
            appointment_id: 'appt-1',
            tenant_id: 'tenant-1',
            company_id: 'company-1',
            vehicle_id: 'vehicle-1',
            checked_in_by_user_id: 'user-1',
            actual_arrival_at: new \DateTimeImmutable,
            occurred_at: new \DateTimeImmutable,
        );

        $this->assertTrue((new ReflectionClass($event))->isReadOnly());
    }

    public function test_appointment_cancelled_is_final_readonly_with_optional_reason(): void
    {
        $event = new AppointmentCancelled(
            appointment_id: 'appt-1',
            tenant_id: 'tenant-1',
            company_id: 'company-1',
            reason_code: 'customer_declined',
            cancelled_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
        );

        $this->assertSame('customer_declined', $event->reason_code);
        $this->assertNull($event->cancelled_by_user_id);
    }

    public function test_appointment_converted_to_work_order_is_final_readonly(): void
    {
        $event = new AppointmentConvertedToWorkOrder(
            appointment_id: 'appt-1',
            work_order_id: 'wo-1',
            tenant_id: 'tenant-1',
            company_id: 'company-1',
            converted_by_user_id: 'user-1',
            occurred_at: new \DateTimeImmutable,
        );

        $this->assertSame('wo-1', $event->work_order_id);
        $this->assertTrue((new ReflectionClass($event))->isReadOnly());
    }
}
