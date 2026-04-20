<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PartNeed;

/**
 * Emitted after successful reservation on Approved transition, informing a
 * downstream procurement agent of the full list of parts required for the WO.
 * The future procurement agent will subscribe.
 *
 * @param  list<PartNeed>  $needs
 */
final readonly class WorkOrderPartsNeeded
{
    /**
     * @param  list<PartNeed>  $needs
     */
    public function __construct(
        public string $work_order_id,
        public array $needs,
        public \DateTimeImmutable $recorded_at,
    ) {}
}
