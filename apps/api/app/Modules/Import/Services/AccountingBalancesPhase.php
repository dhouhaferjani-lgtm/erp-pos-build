<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Finalize phase for the GL opening-balance import type.
 *
 * Routes CSV opening balances through the batch-documented path
 * ({@see AccountingOpeningService}) instead of minting one unsourced,
 * single-legged journal entry per row: the whole file becomes ONE
 * OpeningBalanceBatch keyed to the import job, and the posted journal entry
 * carries source_type='opening_balance' / source_id=<batch id> /
 * is_historical=true with an Opening Balance Equity plug so the entry is
 * balanced by construction.
 *
 * Mirrors {@see PartiesBalancesPhase} (AR/AP open items), which performs the
 * identical batch → addImportRows → validateBatch → postBatch handshake.
 */
final class AccountingBalancesPhase
{
    private const RESULT_KEY = 'gl_balance';

    private const WARNING_CODE = 'balance_not_posted';

    public function __construct(
        private readonly AccountingOpeningService $accountingOpeningService,
        private readonly OpeningBalanceBatchService $batchService,
    ) {}

    /**
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    public function run(ImportJob $job, string $companyId): array
    {
        $company = Company::query()
            ->where('tenant_id', $job->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();

        $balanceRows = $this->collectBalanceRows($job);

        if ($balanceRows === []) {
            return [];
        }

        // The per-row execution phase creates no entity for GL opening balances —
        // it only stages the row and parks its own id in imported_entity_id. Clear
        // that placeholder now; only rows that actually reach the posted journal
        // entry get a real linkage back (below).
        ImportRow::whereIn('id', array_map(
            static fn (array $entry): string => $entry['row']->id,
            $balanceRows
        ))->update(['imported_entity_id' => null]);

        // Nothing the batch handshake can throw may become a job failure: a GL
        // opening balance that cannot be posted is per-row import feedback, and
        // the caller (finalizeImport) runs OUTSIDE the per-row try/catch that
        // protects the execution loop.
        try {
            return $this->postBalances($job, $company, $balanceRows);
        } catch (Throwable $e) {
            return $this->rowResults(
                $balanceRows,
                self::WARNING_CODE,
                $e->getMessage(),
                [self::RESULT_KEY => 'error: batch_failed'],
            );
        }
    }

    /**
     * @param  list<array{row: ImportRow, payload: array<string, string>}>  $balanceRows
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function postBalances(ImportJob $job, Company $company, array $balanceRows): array
    {
        $batch = $this->findImportBatch($job);

        // Already posted for this import job — re-running finalize must not double-post.
        if ($batch !== null && in_array($batch->status, [OpeningBatchStatus::Validated, OpeningBatchStatus::Locked], true)) {
            return $this->rowResults($balanceRows, '', '', [self::RESULT_KEY => 'ok']);
        }

        if ($this->openingBalancesLocked($company->id)) {
            return $this->rowResults(
                $balanceRows,
                self::WARNING_CODE,
                'GL opening balances are locked',
                [self::RESULT_KEY => 'error: opening_locked'],
            );
        }

        if ($batch !== null && $batch->status === OpeningBatchStatus::Draft) {
            $this->batchService->clearImportRows($batch);
        } else {
            if ($this->hasUnlockedBatch($company->id)) {
                return $this->rowResults(
                    $balanceRows,
                    self::WARNING_CODE,
                    'an unlocked GL opening batch already exists - post or delete it, then re-run',
                    [self::RESULT_KEY => 'error: batch_conflict'],
                );
            }

            $batch = $this->batchService->createBatch(
                $company,
                OpeningBatchType::Accounting,
                Carbon::parse($this->defaultBalanceDate($company)),
                'IMPORT-'.substr($job->id, 0, 8).'-GL',
                $job->user_id,
                'unified-import',
            );
            $this->batchService->updateFileReference($batch, [
                'import_job_id' => $job->id,
                'source' => 'unified-import',
            ]);
        }

        $this->batchService->addImportRows($batch, array_map(
            static fn (array $entry): array => $entry['payload'],
            $balanceRows
        ));

        $this->accountingOpeningService->validateBatch($batch->refresh());

        [$warnings, $skippedIndexes] = $this->validationWarnings($batch->refresh(), $balanceRows);

        $postingRows = array_values(array_filter(
            $balanceRows,
            static fn (array $entry, int $index): bool => ! in_array($index, $skippedIndexes, true),
            ARRAY_FILTER_USE_BOTH
        ));

        $validCount = $batch->rows()->where('status', OpeningImportRowStatus::Valid)->count();
        if ($validCount === 0 || $postingRows === []) {
            return $warnings;
        }

        try {
            $entry = $this->accountingOpeningService->postBatch($batch->refresh(), $job->user_id);
        } catch (Throwable $e) {
            return array_merge($warnings, $this->rowResults(
                $postingRows,
                self::WARNING_CODE,
                $e->getMessage(),
                [self::RESULT_KEY => 'error: post_failed'],
            ));
        }

        ImportRow::whereIn('id', array_map(
            static fn (array $item): string => $item['row']->id,
            $postingRows
        ))->update(['imported_entity_id' => $entry->id]);

        return array_merge($warnings, $this->rowResults($postingRows, '', '', [self::RESULT_KEY => 'ok']));
    }

    /**
     * @return list<array{row: ImportRow, payload: array<string, string>}>
     */
    private function collectBalanceRows(ImportJob $job): array
    {
        $balanceRows = [];

        $job->rows()
            ->where('is_imported', true)
            ->orderBy('row_number')
            ->get()
            ->each(function (ImportRow $row) use (&$balanceRows): void {
                $accountCode = trim((string) ($row->data['account_code'] ?? ''));
                if ($accountCode === '') {
                    return;
                }

                $balanceRows[] = [
                    'row' => $row,
                    'payload' => [
                        'account_code' => $accountCode,
                        'debit' => $this->amount($row->data['debit'] ?? null),
                        'credit' => $this->amount($row->data['credit'] ?? null),
                        'description' => $this->lineDescription($row->data),
                    ],
                ];
            });

        return $balanceRows;
    }

    private function amount(mixed $value): string
    {
        $asString = trim((string) ($value ?? ''));

        return $asString === '' ? '0' : $asString;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lineDescription(array $data): string
    {
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $description = 'Opening Balance';
        }

        $reference = trim((string) ($data['reference'] ?? ''));

        return $reference === '' ? $description : $description.' - '.$reference;
    }

    /**
     * Cutover date for an import that carries no explicit one: the start of the
     * company's current fiscal year. Mirrors PartiesBalancesPhase.
     */
    private function defaultBalanceDate(Company $company): string
    {
        $candidate = CarbonImmutable::parse(sprintf('%d-%02d-01', (int) now()->year, $company->fiscal_year_start_month));
        if ($candidate->isAfter(CarbonImmutable::now())) {
            $candidate = $candidate->subYear();
        }

        return $candidate->toDateString();
    }

    private function findImportBatch(ImportJob $job): ?OpeningBalanceBatch
    {
        return OpeningBalanceBatch::query()
            ->where('type', OpeningBatchType::Accounting)
            ->get()
            ->first(fn (OpeningBalanceBatch $batch): bool => ($batch->import_file_reference['import_job_id'] ?? null) === $job->id);
    }

    private function hasUnlockedBatch(string $companyId): bool
    {
        return OpeningBalanceBatch::forCompany($companyId)
            ->ofType(OpeningBatchType::Accounting)
            ->unlocked()
            ->exists();
    }

    private function openingBalancesLocked(string $companyId): bool
    {
        return OpeningBalanceBatch::forCompany($companyId)
            ->ofType(OpeningBatchType::Accounting)
            ->where('status', OpeningBatchStatus::Locked)
            ->exists();
    }

    /**
     * Turn batch-level row validation failures into per-row import warnings and
     * skip them, so a bad line never blocks the rest of the file (markBatchValidated
     * refuses a batch that still holds Invalid rows).
     *
     * @param  list<array{row: ImportRow, payload: array<string, string>}>  $balanceRows
     * @return array{0: list<array{row_id: string, code: string, detail: string, results: array<string, string>}>, 1: list<int>}
     */
    private function validationWarnings(OpeningBalanceBatch $batch, array $balanceRows): array
    {
        $warnings = [];
        $skippedIndexes = [];
        $batchRows = $batch->rows()->orderBy('row_number')->get()->values();

        foreach ($batchRows as $index => $batchRow) {
            if ($batchRow->status !== OpeningImportRowStatus::Invalid) {
                continue;
            }

            if (! isset($balanceRows[$index])) {
                continue;
            }

            $sourceRow = $balanceRows[$index]['row'];
            $warnings[] = [
                'row_id' => $sourceRow->id,
                'code' => self::WARNING_CODE,
                'detail' => implode('; ', $batchRow->getErrorMessages()),
                'results' => [self::RESULT_KEY => 'error: validation_failed'],
            ];
            $skippedIndexes[] = $index;
            $this->batchService->skipImportRow($batchRow);
        }

        return [$warnings, $skippedIndexes];
    }

    /**
     * @param  list<array{row: ImportRow, payload: array<string, string>}>  $rows
     * @param  array<string, string>  $results
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function rowResults(array $rows, string $code, string $detail, array $results): array
    {
        return array_map(
            static fn (array $entry): array => [
                'row_id' => $entry['row']->id,
                'code' => $code,
                'detail' => $detail,
                'results' => $results,
            ],
            $rows
        );
    }
}
