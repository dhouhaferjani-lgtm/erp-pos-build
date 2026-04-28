<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Events;

use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;

/**
 * Emitted when a time entry is opened (either via WorkOrder event listener or manually). Immutable.
 */
final readonly class TechnicianTimeEntryStarted
{
    public function __construct(
        public string $time_entry_id,
        public string $technician_profile_id,
        public TimeEntryType $entry_type,
        public ?string $work_order_id,
        public \DateTimeImmutable $started_at,
    ) {}
}
