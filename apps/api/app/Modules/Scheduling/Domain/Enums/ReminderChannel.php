<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Enums;

/**
 * Reminder delivery channel for an appointment.
 *
 * One row per (appointment, channel, scheduled_for) triple is persisted via
 * the `scheduling_appointment_reminders` table; the enum values are pinned
 * by the DB CHECK constraint `chk_sar_channel`.
 */
enum ReminderChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
