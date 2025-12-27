<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Events;

use App\Modules\Import\Domain\Enums\ImportStatus;
use Carbon\CarbonImmutable;

/**
 * Domain event fired when an import job completes (success or failure).
 */
final readonly class ImportCompleted
{
    public function __construct(
        public string $importJobId,
        public string $tenantId,
        public string $companyId,
        public ImportStatus $status,
        public int $totalRows,
        public int $successfulRows,
        public int $failedRows,
        public string $importType,
        public string $originalFilename,
        public ?string $errorMessage,
        public CarbonImmutable $completedAt,
    ) {}

    /**
     * Check if the import was successful (no failures).
     */
    public function isSuccess(): bool
    {
        return $this->status === ImportStatus::Completed && $this->failedRows === 0;
    }

    /**
     * Check if the import completed with partial success.
     */
    public function isPartialSuccess(): bool
    {
        return $this->status === ImportStatus::Completed && $this->failedRows > 0 && $this->successfulRows > 0;
    }

    /**
     * Check if the import failed completely.
     */
    public function isFailed(): bool
    {
        return $this->status === ImportStatus::Failed;
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
            'successful_rows' => $this->successfulRows,
            'failed_rows' => $this->failedRows,
            'import_type' => $this->importType,
            'original_filename' => $this->originalFilename,
            'error_message' => $this->errorMessage,
            'completed_at' => $this->completedAt->toIso8601String(),
            'is_success' => $this->isSuccess(),
            'is_partial_success' => $this->isPartialSuccess(),
        ];
    }
}
