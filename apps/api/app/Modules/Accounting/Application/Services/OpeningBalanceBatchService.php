<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Service for managing Opening Balance Batches.
 *
 * Handles the complete lifecycle of opening balance imports:
 * - Batch creation and management
 * - CSV import and row staging
 * - Validation status tracking
 * - Batch locking for immutability
 */
class OpeningBalanceBatchService
{
    /**
     * Create a new opening balance batch.
     *
     * @throws RuntimeException If company already has an unlocked batch of same type
     */
    public function createBatch(
        Company $company,
        OpeningBatchType $type,
        Carbon $cutoverDate,
        string $name,
        string $userId,
        ?string $sourceSystem = null
    ): OpeningBalanceBatch {
        // Check for existing unlocked batch of same type
        $existingUnlocked = OpeningBalanceBatch::forCompany($company->id)
            ->ofType($type)
            ->unlocked()
            ->first();

        if ($existingUnlocked !== null) {
            throw new RuntimeException(
                "Company already has an unlocked {$type->label()} batch. ".
                "Please complete or delete the existing batch first."
            );
        }

        return OpeningBalanceBatch::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => $type,
            'name' => $name,
            'cutover_date' => $cutoverDate,
            'status' => OpeningBatchStatus::Draft,
            'source_system' => $sourceSystem,
            'created_by' => $userId,
        ]);
    }

    /**
     * Get all batches for a company.
     *
     * @return Collection<int, OpeningBalanceBatch>
     */
    public function getBatchesForCompany(string $companyId): Collection
    {
        return OpeningBalanceBatch::forCompany($companyId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get batch by ID.
     *
     * @throws RuntimeException If batch not found
     */
    public function getBatch(string $batchId): OpeningBalanceBatch
    {
        $batch = OpeningBalanceBatch::find($batchId);

        if ($batch === null) {
            throw new RuntimeException("Opening balance batch not found: {$batchId}");
        }

        return $batch;
    }

    /**
     * Get batch with all relationships loaded.
     */
    public function getBatchWithRows(string $batchId): OpeningBalanceBatch
    {
        $batch = $this->getBatch($batchId);
        $batch->load(['rows', 'creator', 'validator', 'locker']);

        return $batch;
    }

    /**
     * Delete a draft batch.
     *
     * @throws RuntimeException If batch is not in draft status
     */
    public function deleteBatch(OpeningBalanceBatch $batch): void
    {
        if (! $batch->isDeletable()) {
            throw new RuntimeException(
                "Cannot delete batch in {$batch->status->label()} status. Only draft batches can be deleted."
            );
        }

        DB::transaction(function () use ($batch): void {
            // Delete all import rows first
            $batch->rows()->delete();
            // Delete the batch
            $batch->delete();
        });
    }

    /**
     * Add import rows to a batch.
     *
     * @param array<int, array<string, mixed>> $rows Array of raw CSV rows
     * @throws RuntimeException If batch is not editable
     */
    public function addImportRows(OpeningBalanceBatch $batch, array $rows): void
    {
        if (! $batch->isEditable()) {
            throw new RuntimeException(
                "Cannot add rows to batch in {$batch->status->label()} status."
            );
        }

        $rowType = $batch->type->rowType();
        $currentMaxRow = $batch->rows()->max('row_number') ?? 0;

        $importRows = [];
        foreach ($rows as $index => $row) {
            $importRows[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'batch_id' => $batch->id,
                'row_type' => $rowType,
                'row_number' => $currentMaxRow + $index + 1,
                'raw_data' => json_encode($row),
                'status' => OpeningImportRowStatus::Pending->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        OpeningBalanceImportRow::insert($importRows);
    }

    /**
     * Get all import rows for a batch.
     *
     * @return Collection<int, OpeningBalanceImportRow>
     */
    public function getImportRows(OpeningBalanceBatch $batch): Collection
    {
        return $batch->rows()->orderBy('row_number')->get();
    }

    /**
     * Get paginated import rows.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, OpeningBalanceImportRow>
     */
    public function getImportRowsPaginated(OpeningBalanceBatch $batch, int $perPage = 50): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $batch->rows()
            ->orderBy('row_number')
            ->paginate($perPage);
    }

    /**
     * Update a single import row's mapped data.
     *
     * @param array<string, mixed> $mappedData
     * @throws RuntimeException If row is not editable
     */
    public function updateImportRow(OpeningBalanceImportRow $row, array $mappedData): void
    {
        if (! $row->status->isEditable()) {
            throw new RuntimeException(
                "Cannot update row in {$row->status->label()} status."
            );
        }

        $row->update([
            'mapped_data' => $mappedData,
            'status' => OpeningImportRowStatus::Pending, // Reset to pending for re-validation
            'validation_errors' => null,
        ]);
    }

    /**
     * Mark a row as skipped.
     *
     * @throws RuntimeException If row cannot be skipped
     */
    public function skipImportRow(OpeningBalanceImportRow $row): void
    {
        if ($row->isPosted()) {
            throw new RuntimeException("Cannot skip a row that has already been posted.");
        }

        $row->update(['status' => OpeningImportRowStatus::Skipped]);
    }

    /**
     * Clear all rows from a batch.
     *
     * @throws RuntimeException If batch is not editable
     */
    public function clearImportRows(OpeningBalanceBatch $batch): void
    {
        if (! $batch->isEditable()) {
            throw new RuntimeException(
                "Cannot clear rows from batch in {$batch->status->label()} status."
            );
        }

        $batch->rows()->delete();
    }

    /**
     * Set batch to validated status.
     * Should be called after all rows have been validated by the type-specific service.
     *
     * @throws RuntimeException If batch has no valid rows or has invalid rows
     */
    public function markBatchValidated(OpeningBalanceBatch $batch, string $userId): void
    {
        if (! $batch->canPost()) {
            throw new RuntimeException(
                "Batch is not in draft status. Current status: {$batch->status->label()}"
            );
        }

        $validCount = $batch->getValidRowCount();
        $invalidCount = $batch->getInvalidRowCount();
        $pendingCount = $batch->getPendingRowCount();

        if ($pendingCount > 0) {
            throw new RuntimeException(
                "Cannot validate batch: {$pendingCount} rows are still pending validation."
            );
        }

        if ($invalidCount > 0) {
            throw new RuntimeException(
                "Cannot validate batch: {$invalidCount} rows have validation errors."
            );
        }

        if ($validCount === 0) {
            throw new RuntimeException("Cannot validate batch: no valid rows to process.");
        }

        $batch->update([
            'status' => OpeningBatchStatus::Validated,
            'validated_at' => now(),
            'validated_by' => $userId,
        ]);
    }

    /**
     * Lock a batch after posting.
     * This makes the batch immutable.
     *
     * @throws RuntimeException If batch is not in validated status
     */
    public function lockBatch(OpeningBalanceBatch $batch, string $userId): void
    {
        if (! $batch->canLock()) {
            throw new RuntimeException(
                "Cannot lock batch in {$batch->status->label()} status. Batch must be validated first."
            );
        }

        // Calculate hash for the batch (SHA-256 of all row data)
        $hash = $this->calculateBatchHash($batch);

        // Get previous locked batch hash for chain
        $previousBatch = OpeningBalanceBatch::forCompany($batch->company_id)
            ->ofType($batch->type)
            ->inStatus(OpeningBatchStatus::Locked)
            ->orderBy('locked_at', 'desc')
            ->first();

        $batch->update([
            'status' => OpeningBatchStatus::Locked,
            'locked_at' => now(),
            'locked_by' => $userId,
            'hash' => $hash,
            'previous_hash' => $previousBatch?->hash,
        ]);
    }

    /**
     * Get batch statistics.
     *
     * @return array{total_rows: int, pending: int, valid: int, invalid: int, skipped: int, posted: int}
     */
    public function getBatchStats(OpeningBalanceBatch $batch): array
    {
        $rows = $batch->rows();

        return [
            'total_rows' => $rows->count(),
            'pending' => (clone $rows)->where('status', OpeningImportRowStatus::Pending)->count(),
            'valid' => (clone $rows)->where('status', OpeningImportRowStatus::Valid)->count(),
            'invalid' => (clone $rows)->where('status', OpeningImportRowStatus::Invalid)->count(),
            'skipped' => (clone $rows)->where('status', OpeningImportRowStatus::Skipped)->count(),
            'posted' => (clone $rows)->where('status', OpeningImportRowStatus::Posted)->count(),
        ];
    }

    /**
     * Check if a company has an unlocked opening batch of a specific type.
     * Used to block sales if inventory opening batch is not locked.
     */
    public function hasUnlockedBatch(string $companyId, OpeningBatchType $type): bool
    {
        return OpeningBalanceBatch::forCompany($companyId)
            ->ofType($type)
            ->unlocked()
            ->exists();
    }

    /**
     * Check if inventory opening is ready (locked).
     * Used to determine if sales can proceed.
     */
    public function isInventoryOpeningReady(string $companyId): bool
    {
        // Check if there's any inventory batch
        $hasInventoryBatch = OpeningBalanceBatch::forCompany($companyId)
            ->ofType(OpeningBatchType::Inventory)
            ->exists();

        // If no inventory batch exists, inventory opening is not needed
        if (! $hasInventoryBatch) {
            return true;
        }

        // If there's an inventory batch, it must be locked
        return ! $this->hasUnlockedBatch($companyId, OpeningBatchType::Inventory);
    }

    /**
     * Calculate SHA-256 hash for batch content.
     */
    private function calculateBatchHash(OpeningBalanceBatch $batch): string
    {
        $rows = $batch->rows()->orderBy('row_number')->get();

        $content = $rows->map(function (OpeningBalanceImportRow $row): string {
            return json_encode([
                'row_number' => $row->row_number,
                'raw_data' => $row->raw_data,
                'mapped_data' => $row->mapped_data,
                'mapped_entity_id' => $row->mapped_entity_id,
            ]) ?: '';
        })->implode('|');

        return hash('sha256', $content);
    }

    /**
     * Update file reference for a batch (after file upload).
     *
     * @param array<string, mixed> $fileReference
     */
    public function updateFileReference(OpeningBalanceBatch $batch, array $fileReference): void
    {
        if (! $batch->isEditable()) {
            throw new RuntimeException(
                "Cannot update file reference for batch in {$batch->status->label()} status."
            );
        }

        $batch->update(['import_file_reference' => $fileReference]);
    }

    /**
     * Mark import rows with validation results.
     *
     * @param array<string, array{valid: bool, errors: array<string, array<string>>, mapped_data: array<string, mixed>}> $validationResults
     */
    public function applyValidationResults(OpeningBalanceBatch $batch, array $validationResults): void
    {
        DB::transaction(function () use ($batch, $validationResults): void {
            foreach ($validationResults as $rowId => $result) {
                $row = OpeningBalanceImportRow::find($rowId);

                if ($row === null || $row->batch_id !== $batch->id) {
                    continue;
                }

                $row->update([
                    'status' => $result['valid']
                        ? OpeningImportRowStatus::Valid
                        : OpeningImportRowStatus::Invalid,
                    'validation_errors' => $result['valid'] ? null : $result['errors'],
                    'mapped_data' => $result['mapped_data'],
                ]);
            }
        });
    }

    /**
     * Mark rows as posted with their entity IDs.
     *
     * @param array<string, string> $rowEntityMap Map of row ID => entity ID
     */
    public function markRowsPosted(array $rowEntityMap): void
    {
        DB::transaction(function () use ($rowEntityMap): void {
            foreach ($rowEntityMap as $rowId => $entityId) {
                OpeningBalanceImportRow::where('id', $rowId)
                    ->update([
                        'status' => OpeningImportRowStatus::Posted,
                        'mapped_entity_id' => $entityId,
                    ]);
            }
        });
    }
}
