<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\ImportRowOutcome;
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
        $rows = $job->rows()->reorder();
        $totalRows = (clone $rows)->count();
        $successfulRows = (clone $rows)->where('outcome', ImportRowOutcome::Imported)->count();
        $skippedRows = (clone $rows)->whereIn('outcome', [
            ImportRowOutcome::DuplicateSkipped,
            ImportRowOutcome::DuplicateLoser,
        ])->count();
        $failedRows = (clone $rows)->whereIn('outcome', [
            ImportRowOutcome::Failed,
            ImportRowOutcome::OpeningLocked,
        ])->count();

        return new self(
            totalRows: $totalRows,
            successfulRows: $successfulRows,
            skippedRows: $skippedRows,
            failedRows: $failedRows,
        );
    }
}
