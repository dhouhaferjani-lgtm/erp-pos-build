<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Enums;

/**
 * Lifecycle states for an Appointment aggregate.
 *
 * Four states are system-mirrored from the linked WorkOrder and MUST NOT be
 * writeable from the public transition endpoint — the StatusMachine / the
 * public controller enforce this:
 *   - InProgress  (mirrored from Workshop\WorkOrder\Domain\Events\WorkOrderStarted)
 *   - Completed   (mirrored from WorkOrderCompleted)
 *   - Closed      (mirrored from WorkOrderClosed)
 *   - Cancelled   (reachable via user cancel OR mirrored from WorkOrderCancelled)
 *
 * `NoShow` is reachable via manual operator action only.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Closed = 'closed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether this status is only reachable via a MirrorAppointmentOn*
     * infrastructure listener (public transition endpoint must reject).
     */
    public function isSystemMirrored(): bool
    {
        return match ($this) {
            self::InProgress, self::Completed, self::Closed => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Closed, self::NoShow, self::Cancelled => true,
            default => false,
        };
    }
}
