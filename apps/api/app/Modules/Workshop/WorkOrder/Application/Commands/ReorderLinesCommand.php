<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

/**
 * Reorder lines — accepts a fully-ordered list of line IDs; each line's
 * display_order is set to its index in the payload.
 */
final readonly class ReorderLinesCommand
{
    /**
     * @param  list<string>  $ordered_line_ids
     */
    public function __construct(
        public string $work_order_id,
        public array $ordered_line_ids,
        public string $tenant_id,
        public string $company_id,
    ) {}
}
