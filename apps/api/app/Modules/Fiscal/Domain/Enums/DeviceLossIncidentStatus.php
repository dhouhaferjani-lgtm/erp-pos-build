<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

/**
 * Lifecycle states for a `device_loss_incidents` register row (spec §12).
 *
 * Transitions: `Reported` (initial, DB-defaulted by the migration) →
 * `Recovering` (operator workflow has begun) → `Resolved` (incident closed,
 * unsynced events recovered from an off-device durability path) OR
 * `Unrecoverable` (incident closed, unsynced events lost — fiscal anomaly
 * report required).
 *
 * The whitelist is pinned at the DB layer via a CHECK constraint
 * (`device_loss_incidents_recovery_status_allowed`); adding a new case
 * REQUIRES a new migration that widens the CHECK.
 */
enum DeviceLossIncidentStatus: string
{
    case Reported = 'reported';
    case Recovering = 'recovering';
    case Resolved = 'resolved';
    case Unrecoverable = 'unrecoverable';
}
