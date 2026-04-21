<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PartNeed;

/**
 * Emitted when a WorkOrder transitions into the WaitingParts state because
 * one or more needed parts are not in stock.
 *
 * @param  list<PartNeed>  $needs
 */
final readonly class WorkOrderWaitingParts
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
