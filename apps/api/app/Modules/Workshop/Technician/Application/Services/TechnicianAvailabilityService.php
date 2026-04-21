<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\Services;

use App\Modules\Workshop\Technician\Application\Contracts\TechnicianAvailabilityServiceInterface;
use App\Modules\Workshop\Technician\Domain\Enums\EmploymentStatus;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeOff;
use App\Modules\Workshop\Technician\Domain\ValueObjects\AvailabilityResult;
use App\Modules\Workshop\Technician\Domain\ValueObjects\WeeklySchedule;

/**
 * Core availability logic consumed by Spec B (work-order assignment) and Spec D (scheduler).
 *
 * Evaluation order (matches Spec §7.1):
 *   1. Profile exists + active + employment_status == Active         → else No('inactive'/'not_found')
 *   2. `weekly_schedule` covers [startsAt, startsAt+duration]        → else No('outside_schedule')
 *   3. Approved time-off fully covers the window                     → No('on_leave')
 *      Approved time-off partially covers the window                 → Partial('partial_leave_overlap')
 *   4. Any time-entry overlaps the window                            → Partial('active_assignment')
 *   5. Required specialty held                                        → else Partial('skill_mismatch')
 *   6. otherwise                                                      → Yes
 *
 * `Partial` is returned as soon as a soft-block is detected — no downstream checks run.
 * `No` short-circuits immediately.
 */
final readonly class TechnicianAvailabilityService implements TechnicianAvailabilityServiceInterface
{
    public function isAvailable(
        string $profileId,
        \DateTimeImmutable $startsAt,
        int $durationMinutes,
        ?SpecialtyCode $requiredSpecialty,
    ): AvailabilityResult {
        if ($durationMinutes <= 0) {
            throw new \InvalidArgumentException('durationMinutes must be > 0.');
        }

        $profile = TechnicianProfile::query()
            ->with('company:id,timezone')
            ->find($profileId);

        if (! $profile instanceof TechnicianProfile) {
            return AvailabilityResult::no('not_found');
        }

        // Step 1: active + employment Active
        if (! $profile->is_active) {
            return AvailabilityResult::no('inactive');
        }
        if ($profile->employment_status !== EmploymentStatus::Active) {
            return AvailabilityResult::no('inactive');
        }

        // UTC-normalized request window
        $startUtc = $startsAt->setTimezone(new \DateTimeZone('UTC'));
        $endUtc = $startUtc->modify(sprintf('+%d minutes', $durationMinutes));

        // Step 2: weekly_schedule coverage
        $schedule = WeeklySchedule::fromJson(
            $profile->weekly_schedule,
            $profile->company->timezone,
        );
        if (! $this->windowIsFullyWithinSchedule($schedule, $startUtc, $endUtc)) {
            return AvailabilityResult::no('outside_schedule');
        }

        // Step 3: approved time-off overlap
        $leaveOverlap = $this->evaluateTimeOffOverlap($profile->id, $startUtc, $endUtc);
        if ($leaveOverlap === 'full') {
            return AvailabilityResult::no('on_leave');
        }
        if ($leaveOverlap === 'partial') {
            return AvailabilityResult::partial('partial_leave_overlap');
        }

        // Step 4: existing time-entries
        if ($this->hasTimeEntryOverlap($profile->id, $startUtc, $endUtc)) {
            return AvailabilityResult::partial('active_assignment');
        }

        // Step 5: required specialty
        if ($requiredSpecialty !== null && ! $this->profileHasSpecialty($profile, $requiredSpecialty)) {
            return AvailabilityResult::partial('skill_mismatch');
        }

        return AvailabilityResult::yes();
    }

    /**
     * Checks the schedule covers the entire request window minute-by-minute (15-minute granularity)
     * — returns false if any probed minute falls outside a window (including lunch-break gaps).
     *
     * The schedule VO interprets times in the company timezone; the request is converted before probing.
     */
    private function windowIsFullyWithinSchedule(
        WeeklySchedule $schedule,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
    ): bool {
        // Probe at start, end-1min, and every 15 minutes in between. Windows are lower-inclusive /
        // upper-exclusive so end-1min is the last minute that must be covered.
        $cursor = $startUtc;
        $lastInclusive = $endUtc->modify('-1 minute');

        while ($cursor <= $lastInclusive) {
            if (! $schedule->isWithinSchedule($cursor)) {
                return false;
            }
            $cursor = $cursor->modify('+15 minutes');
        }

        // Also verify the last inclusive minute (in case the loop stepped past).
        return $schedule->isWithinSchedule($lastInclusive);
    }

    /**
     * @return 'none'|'partial'|'full' Classification of approved time-off intersection with the request window.
     */
    private function evaluateTimeOffOverlap(
        string $profileId,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
    ): string {
        /** @var list<TechnicianTimeOff> $overlaps */
        $overlaps = TechnicianTimeOff::query()
            ->where('technician_profile_id', $profileId)
            ->where('is_approved', true)
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->get()
            ->all();

        if ($overlaps === []) {
            return 'none';
        }

        foreach ($overlaps as $leave) {
            $leaveStart = $leave->starts_at->copy()->setTimezone('UTC')->toDateTimeImmutable();
            $leaveEnd = $leave->ends_at->copy()->setTimezone('UTC')->toDateTimeImmutable();

            if ($leaveStart <= $startUtc && $leaveEnd >= $endUtc) {
                return 'full';
            }
        }

        return 'partial';
    }

    private function hasTimeEntryOverlap(
        string $profileId,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
    ): bool {
        return TechnicianTimeEntry::query()
            ->where('technician_profile_id', $profileId)
            ->where('started_at', '<', $endUtc)
            ->where(function ($q) use ($startUtc): void {
                $q->whereNull('ended_at')
                    ->orWhere('ended_at', '>', $startUtc);
            })
            ->exists();
    }

    private function profileHasSpecialty(TechnicianProfile $profile, SpecialtyCode $required): bool
    {
        /** @var iterable<SpecialtyCode> $specialties */
        $specialties = $profile->specialties;
        foreach ($specialties as $code) {
            if ($code === $required) {
                return true;
            }
        }

        return false;
    }
}
