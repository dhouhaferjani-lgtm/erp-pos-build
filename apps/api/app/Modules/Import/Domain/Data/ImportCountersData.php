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

    public static function fromJob(ImportJob $job): self
    {
        return new self(
            $job->total_rows,
            $job->successful_rows,
            $job->skipped_rows,
            $job->failed_rows,
        );
    }
}
