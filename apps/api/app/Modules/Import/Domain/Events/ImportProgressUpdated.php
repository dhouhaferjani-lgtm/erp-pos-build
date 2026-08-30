<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Events;

use App\Modules\Import\Domain\Enums\ImportStatus;

/**
 * Domain event fired when import progress is updated.
 * Triggered periodically during import execution (e.g., every 100 rows).
 */
final readonly class ImportProgressUpdated
{
    public function __construct(
        public string $importJobId,
        public string $tenantId,
        public string $companyId,
        public ImportStatus $status,
        public int $totalRows,
        public int $processedRows,
        public int $successfulRows,
        public int $failedRows,
        public string $importType,
        public string $originalFilename,
        public int $skippedRows = 0,
    ) {}

    /**
     * Get progress as a percentage (0-100).
     */
    public function getProgressPercentage(): int
    {
        if ($this->totalRows === 0) {
            return 0;
        }

        return (int) round(($this->processedRows / $this->totalRows) * 100);
    }

    /**
     * Convert to array for broadcasting.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'import_job_id' => $this->importJobId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'status' => $this->status->value,
            'total_rows' => $this->totalRows,
            'processed_rows' => $this->processedRows,
            'successful_rows' => $this->successfulRows,
            'failed_rows' => $this->failedRows,
            'skipped_rows' => $this->skippedRows,
            'progress_percentage' => $this->getProgressPercentage(),
            'import_type' => $this->importType,
            'original_filename' => $this->originalFilename,
        ];
    }
}
