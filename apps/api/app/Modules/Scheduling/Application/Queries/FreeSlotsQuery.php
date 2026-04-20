<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Queries;

use App\Modules\Scheduling\Application\Services\CapacityCalculationService;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilityWindow;

/**
 * Flattens all per-bay availability windows across a date range into a
 * single chronological list of `AvailabilityWindow`s that satisfy a
 * minimum duration threshold.
 *
 * Intended consumers:
 *   - Staff rescheduling UI — "find me a 90-minute slot between tomorrow
 *     and next Tuesday for bay X".
 *   - Storefront availability controller — strips `resource_id` before
 *     exposing to the public, so the public endpoint returns a
 *     duration-filtered list of opaque time windows.
 *
 * Reuses {@see CapacityCalculationService} as the single source of truth
 * for free-window computation (half-open `[start, end)` semantics matching
 * the GiST exclusion constraint predicate).
 */
final class FreeSlotsQuery
{
    public function __construct(
        private readonly CapacityCalculationService $capacity,
    ) {}

    /**
     * @param  int  $durationMinutes  Minimum slot length to consider (free windows shorter than
     *                                this value are discarded).
     * @param  \DateTimeImmutable  $earliestFrom  Inclusive lower bound for the search range (midnight-aligned).
     * @param  \DateTimeImmutable  $latestUntil  Exclusive upper bound for the search range.
     * @return list<AvailabilityWindow> Chronologically sorted free windows spanning every bay at the
     *                                  company, each at least `$durationMinutes` long.
     */
    public function find(
        string $companyId,
        int $durationMinutes,
        \DateTimeImmutable $earliestFrom,
        \DateTimeImmutable $latestUntil,
    ): array {
        if ($durationMinutes <= 0) {
            throw new \InvalidArgumentException('durationMinutes must be positive.');
        }
        if ($latestUntil <= $earliestFrom) {
            throw new \InvalidArgumentException('latestUntil must be strictly after earliestFrom.');
        }

        /** @var list<AvailabilityWindow> $slots */
        $slots = [];

        $cursor = $earliestFrom->setTime(0, 0, 0);
        $endCursor = $latestUntil;
        while ($cursor < $endCursor) {
            $daySlots = $this->capacity->availabilityForDay($companyId, $cursor);
            foreach ($daySlots as $windows) {
                foreach ($windows as $window) {
                    if ($window->durationMinutes() < $durationMinutes) {
                        continue;
                    }
                    if ($window->ends_at <= $earliestFrom || $window->starts_at >= $latestUntil) {
                        continue;
                    }
                    // Clip to requested range if partially outside.
                    $clippedStart = $window->starts_at < $earliestFrom ? $earliestFrom : $window->starts_at;
                    $clippedEnd = $window->ends_at > $latestUntil ? $latestUntil : $window->ends_at;
                    if ((int) (($clippedEnd->getTimestamp() - $clippedStart->getTimestamp()) / 60) < $durationMinutes) {
                        continue;
                    }
                    $slots[] = new AvailabilityWindow(
                        resource_type: $window->resource_type,
                        resource_id: $window->resource_id,
                        starts_at: $clippedStart,
                        ends_at: $clippedEnd,
                    );
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        usort(
            $slots,
            fn (AvailabilityWindow $a, AvailabilityWindow $b): int => $a->starts_at <=> $b->starts_at,
        );

        return $slots;
    }
}
