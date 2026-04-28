<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Services;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Exceptions\InvalidAppointmentTransitionException;

/**
 * Appointment status-transition guard.
 *
 * Full adjacency matrix (the combined matrix used by `isAllowed` covers
 * every legal edge, regardless of whether the caller is the public API
 * or a system listener):
 *
 *   Scheduled  → Confirmed | CheckedIn | Cancelled | NoShow
 *   Confirmed  → CheckedIn | Cancelled | NoShow
 *   CheckedIn  → InProgress (system) | NoShow | Cancelled
 *   InProgress → Completed (system) | Cancelled (system)
 *   Completed  → Closed (system)
 *   Closed     → (terminal)
 *   NoShow     → (terminal)
 *   Cancelled  → (terminal)
 *
 * The 4 system-mirrored target states (InProgress / Completed / Closed,
 * plus Cancelled-post-WO) are reachable ONLY via the 4 Mirror* listeners.
 *
 *   isAllowed                     — full matrix, any caller
 *   isAllowedForPublicTransition  — matrix MINUS system-mirrored targets;
 *                                   used by the public HTTP controller
 *   isAllowedForSystemMirror      — the system-only supplement (the 3
 *                                   edges the public path refuses)
 */
final class AppointmentStatusMachine
{
    /**
     * @var array<string, list<AppointmentStatus>>
     */
    private const ALLOWED = [
        'scheduled' => [
            AppointmentStatus::Confirmed,
            AppointmentStatus::CheckedIn,
            AppointmentStatus::Cancelled,
            AppointmentStatus::NoShow,
        ],
        'confirmed' => [
            AppointmentStatus::CheckedIn,
            AppointmentStatus::Cancelled,
            AppointmentStatus::NoShow,
        ],
        'checked_in' => [
            AppointmentStatus::InProgress,
            AppointmentStatus::NoShow,
            AppointmentStatus::Cancelled,
        ],
        'in_progress' => [
            AppointmentStatus::Completed,
            AppointmentStatus::Cancelled,
        ],
        'completed' => [
            AppointmentStatus::Closed,
        ],
        'closed' => [],
        'no_show' => [],
        'cancelled' => [],
    ];

    public function isAllowed(AppointmentStatus $from, AppointmentStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::ALLOWED[$from->value], true);
    }

    /**
     * Is this transition allowed from the public HTTP transition endpoint?
     *
     * Excludes every edge whose target is one of the 3 system-mirrored
     * states (InProgress / Completed / Closed). Cancelled is still
     * reachable via the public path (operator pre-WO cancellation); it
     * becomes mirrored-only once a WorkOrder has been created.
     */
    public function isAllowedForPublicTransition(AppointmentStatus $from, AppointmentStatus $to): bool
    {
        if ($to->isSystemMirrored()) {
            return false;
        }

        return $this->isAllowed($from, $to);
    }

    /**
     * Is this transition allowed when driven by a MirrorAppointmentOn*
     * listener? Only the 3 system-mirrored edges qualify — listeners are
     * the authorized path for those specific edges.
     */
    public function isAllowedForSystemMirror(AppointmentStatus $from, AppointmentStatus $to): bool
    {
        if (! $to->isSystemMirrored()) {
            return $this->isAllowed($from, $to);
        }

        return $this->isAllowed($from, $to);
    }

    /**
     * Throws if the transition is not in the combined matrix.
     *
     * @throws InvalidAppointmentTransitionException
     */
    public function assertAllowed(AppointmentStatus $from, AppointmentStatus $to): void
    {
        if (! $this->isAllowed($from, $to)) {
            throw new InvalidAppointmentTransitionException($from, $to);
        }
    }

    /**
     * Throws if the transition is not allowed on the public path.
     *
     * @throws InvalidAppointmentTransitionException
     */
    public function assertAllowedForPublicTransition(AppointmentStatus $from, AppointmentStatus $to): void
    {
        if (! $this->isAllowedForPublicTransition($from, $to)) {
            $msg = $to->isSystemMirrored()
                ? "Status '{$to->value}' is reserved for system-mirrored transitions (status_reserved_for_system_mirror)."
                : '';
            throw new InvalidAppointmentTransitionException($from, $to, $msg);
        }
    }

    /**
     * Mutate the appointment's status if the transition is valid via the
     * combined matrix. Does NOT persist; caller is responsible for saving.
     *
     * @throws InvalidAppointmentTransitionException
     */
    public function transition(Appointment $appointment, AppointmentStatus $next): void
    {
        $this->assertAllowed($appointment->status, $next);
        $appointment->status = $next;
    }
}
