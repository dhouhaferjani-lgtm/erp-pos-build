<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

/**
 * Records the technician diagnosis. Prepares WO for transition to Diagnosed.
 */
final readonly class RecordDiagnosisCommand
{
    public function __construct(
        public string $work_order_id,
        public string $diagnosis,
    ) {}
}
