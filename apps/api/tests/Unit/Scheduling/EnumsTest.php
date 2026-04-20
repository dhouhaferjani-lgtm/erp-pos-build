<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\BayType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use PHPUnit\Framework\TestCase;

/**
 * Locks enum case values so DB CHECK constraints, seeders, and frontend
 * translations cannot silently drift.
 */
final class EnumsTest extends TestCase
{
    public function test_appointment_status_values_match_db_check_constraint(): void
    {
        $this->assertSame(
            ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'closed', 'no_show', 'cancelled'],
            AppointmentStatus::values(),
        );
    }

    public function test_appointment_status_system_mirrored_states(): void
    {
        $this->assertTrue(AppointmentStatus::InProgress->isSystemMirrored());
        $this->assertTrue(AppointmentStatus::Completed->isSystemMirrored());
        $this->assertTrue(AppointmentStatus::Closed->isSystemMirrored());
        $this->assertFalse(AppointmentStatus::Scheduled->isSystemMirrored());
        $this->assertFalse(AppointmentStatus::Confirmed->isSystemMirrored());
        $this->assertFalse(AppointmentStatus::CheckedIn->isSystemMirrored());
        $this->assertFalse(AppointmentStatus::Cancelled->isSystemMirrored());
        $this->assertFalse(AppointmentStatus::NoShow->isSystemMirrored());
    }

    public function test_appointment_status_terminal_states(): void
    {
        $this->assertTrue(AppointmentStatus::Closed->isTerminal());
        $this->assertTrue(AppointmentStatus::NoShow->isTerminal());
        $this->assertTrue(AppointmentStatus::Cancelled->isTerminal());
        $this->assertFalse(AppointmentStatus::Scheduled->isTerminal());
        $this->assertFalse(AppointmentStatus::InProgress->isTerminal());
    }

    public function test_bay_type_values_match_db_check_constraint(): void
    {
        $this->assertSame(
            ['general', 'quick_service', 'alignment', 'heavy', 'specialist', 'flat', 'other'],
            BayType::values(),
        );
    }

    public function test_appointment_type_values(): void
    {
        $this->assertSame(
            ['quick_service', 'inspection', 'diagnostic', 'standard_repair', 'major_repair', 'maintenance', 'tire_service', 'bodywork', 'other'],
            AppointmentType::values(),
        );
    }

    public function test_wait_type_values(): void
    {
        $this->assertSame(
            ['waiter', 'drop_off', 'pickup_scheduled'],
            WaitType::values(),
        );
    }

    public function test_appointment_source_values(): void
    {
        $this->assertSame(
            ['manual', 'phone', 'online', 'walkin'],
            AppointmentSource::values(),
        );
    }
}
