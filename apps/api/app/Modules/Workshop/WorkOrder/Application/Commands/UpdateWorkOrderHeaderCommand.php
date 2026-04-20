<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

/**
 * Header-only update (diagnosis, notes, scheduling, primary tech). Does NOT
 * touch status (use TransitionStatusCommand) or lines (use line commands).
 */
final readonly class UpdateWorkOrderHeaderCommand
{
    public function __construct(
        public string $work_order_id,
        public ?string $primary_technician_profile_id,
        public ?string $diagnosis,
        public ?string $internal_notes,
        public ?\DateTimeImmutable $scheduled_start_at,
        public ?\DateTimeImmutable $scheduled_end_at,
        public ?\DateTimeImmutable $promised_at,
    ) {}
}
