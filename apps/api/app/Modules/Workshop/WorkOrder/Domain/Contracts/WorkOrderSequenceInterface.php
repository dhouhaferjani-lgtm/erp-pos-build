<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Contracts;

/**
 * Provides the next `work_order_number` for a given company.
 *
 * Format is `WO-YYYY-NNNN` per Task 8. Implementation is pessimistic-locked
 * on `(company_id, year)` to guarantee gap-free monotonic numbering (mirrors
 * the DocumentNumberingService pattern).
 */
interface WorkOrderSequenceInterface
{
    public function next(string $companyId): string;
}
