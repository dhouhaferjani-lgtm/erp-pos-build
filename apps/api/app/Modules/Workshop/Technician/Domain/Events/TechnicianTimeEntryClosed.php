<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Events;

/**
 * Emitted when a time entry is closed and its duration finalized. Immutable.
 */
final readonly class TechnicianTimeEntryClosed
{
    public function __construct(
        public string $time_entry_id,
        public string $technician_profile_id,
        public int $duration_minutes,
        public \DateTimeImmutable $ended_at,
    ) {}
}
