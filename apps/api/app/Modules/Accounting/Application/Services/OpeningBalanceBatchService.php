<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     * Quantity values are always staged at scale 4 (independent of currency).
     */
    private const QUANTITY_SCALE = 4;

    /**
     * Money values are always staged at the fixed STORAGE scale 3 (independent
     * of currency). This matches the 3-decimal ingress regex (Phase 4.5) and
     * the decimal(N,3) journal-posting target columns this staging feeds.
     *
     * Canonicalizing at the per-currency display scale (e.g. EUR scale 2) would
     * silently truncate the 3rd decimal the regex accepts (10000.105 -> 10000.10)
     * before it ever reaches the decimal(N,3) columns.
     */
    private const MONEY_STORAGE_SCALE = 3;

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
                'Please complete or delete the existing batch first.'
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
     * Re-read a batch FOR UPDATE and assert it is still postable.
     *
     * MUST be the FIRST statement inside a posting transaction. Every postBatch()
     * used to evaluate `$batch->canPost()` on an in-memory model read BEFORE the
     * transaction opened, and the in-transaction re-check (markBatchValidated) read
     * that SAME stale model — so two overlapping posts both passed the guard, both
     * wrote their side effects, and the unconditional lock-write silently re-pointed
     * the batch hash chain at the second one. Row-level locking here makes the
     * second transaction block until the first commits and then see the committed
     * status, which is no longer DRAFT.
     *
     * `lockForUpdate()` compiles to nothing on sqlite (the test runner), where
     * concurrency is not meaningful; the claim-style conditional writes in
     * {@see self::markBatchValidated()} / {@see self::lockBatch()} /
     * {@see self::markRowsPosted()} are the driver-independent backstop.
     *
     * @throws RuntimeException If the batch is gone or no longer in draft status
     */
    public function lockBatchForPosting(string $batchId): OpeningBalanceBatch
    {
        $batch = OpeningBalanceBatch::query()
            ->whereKey($batchId)
            ->lockForUpdate()
            ->first();

        if ($batch === null) {
            throw new RuntimeException("Opening balance batch not found: {$batchId}");
        }

        if (! $batch->canPost()) {
            throw new RuntimeException(
                "Cannot post batch in {$batch->status->label()} status. Batch must be in draft status."
            );
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
     * @param  array<int, array<string, mixed>>  $rows  Array of raw CSV rows
     *
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
            $canonical = $this->canonicalizeRow($batch->type, $row);

            $importRows[] = [
                'id' => (string) Str::uuid(),
                'batch_id' => $batch->id,
                'row_type' => $rowType,
                'row_number' => $currentMaxRow + $index + 1,
                'raw_data' => json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION),
                'status' => OpeningImportRowStatus::Pending->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        OpeningBalanceImportRow::insert($importRows);
    }

    /**
     * Pre-canonicalize the numeric fields of a staging row to fixed-scale numeric strings.
     *
     * JSONB columns bypass Eloquent decimal casts, so numeric values must be written as
     * canonical numeric-strings (with trailing zeros preserved) BEFORE json_encode.
     * Monetary fields use the fixed STORAGE scale 3 (matching the 3dp ingress regex and
     * the decimal(N,3) journal-posting target columns), NOT the per-currency display scale
     * which would silently truncate the 3rd decimal. Quantity is always scale 4.
     * Non-numeric and unknown fields are passed through untouched.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function canonicalizeRow(OpeningBatchType $type, array $row): array
    {
        $moneyScale = self::MONEY_STORAGE_SCALE;

        // Field => scale map per batch type.
        $moneyFields = match ($type) {
            OpeningBatchType::Accounting => ['debit', 'credit'],
            OpeningBatchType::Inventory => ['unit_cost'],
            OpeningBatchType::ArOpenItems, OpeningBatchType::ApOpenItems => ['total', 'open_amount'],
        };

        foreach ($moneyFields as $field) {
            $row[$field] = $this->canonicalizeField($row[$field] ?? null, $moneyScale);
        }

        if ($type === OpeningBatchType::Inventory) {
            $row['quantity'] = $this->canonicalizeField($row['quantity'] ?? null, self::QUANTITY_SCALE);
        }

        return $row;
    }

    /**
     * Canonicalize a single numeric field value to a fixed-scale numeric string.
     *
     * Leaves null, empty, and non-numeric values untouched (validation surfaces those
     * downstream) so canonicalization never silently mutates malformed input.
     */
    private function canonicalizeField(mixed $value, int $scale): mixed
    {
        if ($value === null) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return $value;
        }

        $asString = is_string($value) ? trim($value) : (string) $value;

        if ($asString === '' || ! is_numeric($asString)) {
            return $value;
        }

        return CurrencyScale::bcformatStrict($asString, $scale);
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
     * @return LengthAwarePaginator<int, OpeningBalanceImportRow>
     */
    public function getImportRowsPaginated(OpeningBalanceBatch $batch, int $perPage = 50): LengthAwarePaginator
    {
        return $batch->rows()
            ->orderBy('row_number')
            ->paginate($perPage);
    }

    /**
     * Update a single import row's mapped data.
     *
     * @param  array<string, mixed>  $mappedData
     *
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
            throw new RuntimeException('Cannot skip a row that has already been posted.');
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
            throw new RuntimeException('Cannot validate batch: no valid rows to process.');
        }

        // CLAIM, not a blind write. A conditional single-statement UPDATE keyed on
        // the CURRENT committed status is the driver-independent DB backstop for the
        // whole double-post class: whoever loses the race updates 0 rows and is
        // refused, whatever the in-memory model believed. This is the AR/AP arm's
        // only DB-level guarantee (it writes N documents, none of which carries a
        // column that could bear a per-batch unique index).
        $now = now();

        $claimed = OpeningBalanceBatch::query()
            ->whereKey($batch->id)
            ->where('status', OpeningBatchStatus::Draft)
            ->update([
                'status' => OpeningBatchStatus::Validated,
                'validated_at' => $now,
                'validated_by' => $userId,
            ]);

        if ($claimed !== 1) {
            throw new RuntimeException(
                'Batch is no longer in draft status: a concurrent request already transitioned it. '
                .'Refusing to re-validate.'
            );
        }

        $this->syncClaimedAttributes($batch, [
            'status' => OpeningBatchStatus::Validated,
            'validated_at' => $now,
            'validated_by' => $userId,
            'updated_at' => $now,
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

        // Claim VALIDATED -> LOCKED conditionally. The previous unconditional write
        // would happily re-seal an already-LOCKED batch, re-pointing hash /
        // previous_hash at a second posting and breaking the chain.
        $now = now();

        $claimed = OpeningBalanceBatch::query()
            ->whereKey($batch->id)
            ->where('status', OpeningBatchStatus::Validated)
            ->update([
                'status' => OpeningBatchStatus::Locked,
                'locked_at' => $now,
                'locked_by' => $userId,
                'hash' => $hash,
                'previous_hash' => $previousBatch?->hash,
            ]);

        if ($claimed !== 1) {
            throw new RuntimeException(
                'Batch is no longer in validated status: a concurrent request already locked it. '
                .'Refusing to re-seal the hash chain.'
            );
        }

        $this->syncClaimedAttributes($batch, [
            'status' => OpeningBatchStatus::Locked,
            'locked_at' => $now,
            'locked_by' => $userId,
            'hash' => $hash,
            'previous_hash' => $previousBatch?->hash,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reflect a claim-style conditional UPDATE onto the caller's in-memory model.
     *
     * Deliberately NOT `$batch->refresh()`: a full re-read would also re-hydrate
     * unrelated JSONB columns, and PostgreSQL's `jsonb` does not preserve key order
     * (it stores keys sorted by length then bytewise), so refreshing silently
     * reorders e.g. `import_file_reference` relative to what the caller wrote.
     * Only the columns this claim actually wrote are synced.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncClaimedAttributes(OpeningBalanceBatch $batch, array $attributes): void
    {
        $batch->forceFill($attributes);
        $batch->syncOriginalAttributes(array_keys($attributes));
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
     * @param  array<string, mixed>  $fileReference
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
     * Enforces {@see OpeningImportRowStatus::isEditable()} at the WRITE path, not
     * only at the caller's row selection: this method used to overwrite `status` and
     * `mapped_data` on ANY row it was handed, POSTED ones included — and
     * `mapped_data` is folded into the SHA-256 batch seal
     * ({@see self::calculateBatchHash()}), so rewriting it silently invalidated the
     * stored hash of a LOCKED batch.
     *
     * @param  array<string, array{valid: bool, errors: array<string, array<string>>, mapped_data: array<string, mixed>}>  $validationResults
     *
     * @throws RuntimeException If any targeted row is no longer editable
     */
    public function applyValidationResults(OpeningBalanceBatch $batch, array $validationResults): void
    {
        DB::transaction(function () use ($batch, $validationResults): void {
            foreach ($validationResults as $rowId => $result) {
                $row = OpeningBalanceImportRow::find($rowId);

                if ($row === null || $row->batch_id !== $batch->id) {
                    continue;
                }

                if (! $row->status->isEditable()) {
                    throw new RuntimeException(
                        "Cannot apply validation results to row {$rowId} in {$row->status->label()} status."
                    );
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
     * Each row is CLAIMED with a conditional UPDATE constrained to the editable
     * statuses, asserting exactly one affected row. That makes the row itself a
     * DB-level, driver-independent single-posting backstop: a second posting of the
     * same batch cannot re-point an already-POSTED row at a second journal line /
     * document / stock movement, whatever the caller's in-memory state believed.
     *
     * @param  array<string, string>  $rowEntityMap  Map of row ID => entity ID
     *
     * @throws RuntimeException If any row is no longer in an editable status
     */
    public function markRowsPosted(array $rowEntityMap): void
    {
        DB::transaction(function () use ($rowEntityMap): void {
            $editableStatuses = self::editableRowStatuses();

            foreach ($rowEntityMap as $rowId => $entityId) {
                $claimed = OpeningBalanceImportRow::query()
                    ->where('id', $rowId)
                    ->whereIn('status', $editableStatuses)
                    ->update([
                        'status' => OpeningImportRowStatus::Posted,
                        'mapped_entity_id' => $entityId,
                    ]);

                if ($claimed !== 1) {
                    throw new RuntimeException(
                        "Cannot mark row {$rowId} as posted: it is no longer in an editable status "
                        .'(already posted or skipped).'
                    );
                }
            }
        });
    }

    /**
     * The row statuses the enum itself declares mutable, derived from the enum so
     * the two can never drift apart.
     *
     * @return list<OpeningImportRowStatus>
     */
    private static function editableRowStatuses(): array
    {
        return array_values(array_filter(
            OpeningImportRowStatus::cases(),
            static fn (OpeningImportRowStatus $status): bool => $status->isEditable(),
        ));
    }
}
