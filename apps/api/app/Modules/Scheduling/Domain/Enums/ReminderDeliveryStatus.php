<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Enums;

/**
 * Lifecycle status for a single reminder row.
 *
 * pending  — queued, waiting for the dispatch job.
 * sent     — transport acknowledged (sent_at populated).
 * failed   — transport error; the dispatch job may requeue.
 * skipped  — the appointment moved to a terminal state before dispatch.
 */
enum ReminderDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
