<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A typed window of availability for a bay or technician resource.
 *
 * Half-open `[starts_at, ends_at)` semantics match the GiST exclusion
 * constraint `tstzrange(scheduled_start, scheduled_end, '[)')` — two
 * windows that touch at the boundary (`endA == startB`) do NOT overlap.
 */
final readonly class AvailabilityWindow
{
    public const TYPE_BAY = 'bay';

    public const TYPE_TECHNICIAN = 'technician';

    public function __construct(
        public string $resource_type,
        public string $resource_id,
        public \DateTimeImmutable $starts_at,
        public \DateTimeImmutable $ends_at,
    ) {
        if ($resource_type !== self::TYPE_BAY && $resource_type !== self::TYPE_TECHNICIAN) {
            throw new InvalidArgumentException(
                "resource_type must be 'bay' or 'technician', got '{$resource_type}'.",
            );
        }
        if ($ends_at <= $starts_at) {
            throw new InvalidArgumentException(
                'AvailabilityWindow ends_at must be strictly after starts_at.',
            );
        }
    }

    public function durationMinutes(): int
    {
        return (int) (($this->ends_at->getTimestamp() - $this->starts_at->getTimestamp()) / 60);
    }

    /**
     * Half-open overlap: `[aStart, aEnd) ∩ [bStart, bEnd) ≠ ∅` iff
     * `aStart < bEnd AND bStart < aEnd`. Touching boundaries do NOT
     * overlap — matches the GiST `tstzrange(..., '[)')` predicate.
     */
    public function overlaps(self $other): bool
    {
        return $this->starts_at < $other->ends_at
            && $other->starts_at < $this->ends_at;
    }
}
