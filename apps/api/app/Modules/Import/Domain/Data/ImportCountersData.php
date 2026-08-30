<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\ImportJob;

final readonly class ImportCountersData
{
    public function __construct(
        public int $totalRows,
        public int $successfulRows,
        public int $skippedRows,
        public int $failedRows,
    ) {}

    /**
     * Recompute terminal aggregates from the row authority available in Wave 0.
     *
     * G-4 replaces this compatibility calculation with the §3.2.2 outcome
     * equations when its M6a outcome column and enum land. Until then
     * skippedRows is deliberately fixed at zero and is not meaningful to API,
     * frontend, or workbook consumers. The only durable terminal discriminator
     * is is_imported; every row that did not import is reported failed.
     */
    public static function fromJob(ImportJob $job): self
    {
        $rows = $job->rows()->reorder();
        $totalRows = (clone $rows)->count();
        $successfulRows = (clone $rows)->where('is_imported', true)->count();

        return new self(
            totalRows: $totalRows,
            successfulRows: $successfulRows,
            skippedRows: 0,
            failedRows: $totalRows - $successfulRows,
        );
    }
}
