<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\ValueObjects;

/**
 * Tri-state result of TechnicianAvailabilityService::isAvailable.
 *
 * - `Yes`: free; reason string empty.
 * - `No($reason)`: hard block (inactive, outside_schedule, on_leave, not_found).
 * - `Partial($reason)`: soft block (partial_leave_overlap, active_assignment, skill_mismatch).
 *
 * Callers surface `Partial` as a warning rather than a block — skill matching UIs
 * can surface "candidate but not ideal" flags, and the scheduler (Spec D) may
 * override on manager action.
 */
final readonly class AvailabilityResult
{
    private const STATUS_YES = 'yes';

    private const STATUS_NO = 'no';

    private const STATUS_PARTIAL = 'partial';

    private function __construct(
        public string $status,
        public string $reason,
    ) {}

    public static function yes(): self
    {
        return new self(self::STATUS_YES, '');
    }

    public static function no(string $reason): self
    {
        return new self(self::STATUS_NO, $reason);
    }

    public static function partial(string $reason): self
    {
        return new self(self::STATUS_PARTIAL, $reason);
    }

    public function isYes(): bool
    {
        return $this->status === self::STATUS_YES;
    }

    public function isNo(): bool
    {
        return $this->status === self::STATUS_NO;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }
}
