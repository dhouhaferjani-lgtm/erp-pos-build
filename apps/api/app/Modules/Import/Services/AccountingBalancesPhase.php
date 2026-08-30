<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Shared\Exceptions\UnboundCompanyContextException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use RuntimeException;

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

    /**
     * Matches AccountingOpeningService's posted-entry source_type.
     */
    private const SOURCE_TYPE = 'opening_balance';

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

        // The per-row execution phase creates no entity for GL opening balances —
        // it only stages the row and parks its own id in imported_entity_id. Clear
        // that placeholder for EVERY row of the job (before any early return, so a
        // row we decline to collect cannot keep a row id masquerading as an entity
        // id); only rows that reach the posted journal entry get a real linkage.
        $job->rows()->update(['imported_entity_id' => null]);

        $balanceRows = $this->collectBalanceRows($job);

        if ($balanceRows === []) {
            return [];
        }

        // A domain refusal (unmappable account, locked/conflicting batch, missing
        // OBE account) is per-row import feedback, never a job failure — and
        // finalizeImport runs OUTSIDE the per-row try/catch that protects the
        // execution loop. The catch is deliberately narrowed to RuntimeException
        // (what the batch/opening services throw for refusals): a TypeError, a
        // QueryException or any other infrastructure fault must stay LOUD rather
        // than become a silent, green, empty import.
        try {
            $results = $this->postBalances($job, $company, $balanceRows);
        } catch (UnboundCompanyContextException $e) {
            // Never swallow this one: it is the exact shape of the async
            // regression this phase was fixed for (rule 19 / rule 20).
            throw $e;
        } catch (RuntimeException $e) {
            $this->discardOwnDraftBatch($job, $company->id);

            $results = $this->rowResults(
                $balanceRows,
                self::WARNING_CODE,
                $e->getMessage(),
                [self::RESULT_KEY => 'error: batch_failed'],
            );
        }

        return $results;
    }

    /**
     * @param  list<array{row: ImportRow, payload: array<string, string>}>  $balanceRows
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function postBalances(ImportJob $job, Company $company, array $balanceRows): array
    {
        $batch = $this->findImportBatch($job, $company->id);

        // Already posted for this import job — re-running finalize must not
        // double-post. Only Locked is checked: postBatch marks Validated and locks
        // inside the SAME transaction, so a Validated-but-unposted batch cannot
        // exist; treating it as posted would return a false 'ok' if some future
        // path ever created one. A stray Validated batch instead falls through to
        // the unlocked-batch conflict below, which posts nothing.
        if ($batch !== null && $batch->status === OpeningBatchStatus::Locked) {
            return $this->postedResults($balanceRows, $batch);
        }

        // ALL-OR-NOTHING, FILE-SCOPED. A batch-scoped guard cannot see a row the
        // IMPORT's own ingress rejected: the execution loop only runs for is_valid
        // rows (ImportService::getValidRows) and canStart() needs only ONE valid row,
        // so such a row never reaches the batch and would be silently dropped from a
        // posted, Locked, undeletable opening balance. Comparing the collected rows
        // against every row the FILE supplied catches all of them at once —
        // validation failures, the 3-decimal money ceiling, execution errors, and
        // rows this phase itself declined to collect.
        $excludedRows = $job->rows()->count() - count($balanceRows);

        if ($excludedRows > 0) {
            $this->discardOwnDraftBatch($job, $company->id);

            return $this->rowResults(
                $balanceRows,
                self::WARNING_CODE,
                sprintf(
                    'the file was not posted: %d row(s) were rejected before posting - correct them and re-import the whole file',
                    $excludedRows
                ),
                [self::RESULT_KEY => 'error: file_not_posted'],
            );
        }

        if ($this->openingBalancesLocked($company->id)) {
            $this->discardOwnDraftBatch($job, $company->id);

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
                $this->discardOwnDraftBatch($job, $company->id);

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

        // ALL-OR-NOTHING. An opening balance is entered once and locked forever
        // (postBatch locks inside its own transaction, and a Locked batch is not
        // deletable), so posting only the mappable subset of a file would freeze an
        // understated opening equity that neither a re-import nor the accountant's
        // UI wizard could ever correct. The wizard enforces this through
        // markBatchValidated ("N rows have validation errors"); the import must not
        // route around that guard by pre-skipping bad rows.
        $rejections = $this->rowRejections($batch->refresh(), $balanceRows);

        if ($rejections !== []) {
            $this->discardOwnDraftBatch($job, $company->id);

            return array_merge($rejections, $this->blockedResults($balanceRows, $rejections));
        }

        $validCount = $batch->rows()->where('status', OpeningImportRowStatus::Valid)->count();
        if ($validCount !== count($balanceRows)) {
            $this->discardOwnDraftBatch($job, $company->id);

            return $this->rowResults(
                $balanceRows,
                self::WARNING_CODE,
                'the file was not posted: not every row could be validated',
                [self::RESULT_KEY => 'error: file_not_posted'],
            );
        }

        try {
            $entry = $this->accountingOpeningService->postBatch($batch->refresh(), $job->user_id);
        } catch (UnboundCompanyContextException $e) {
            // Same guard as the outer catch: an unbound-context fault is the C1 shape
            // and must stay LOUD, never degrade to a per-row warning. (Symmetry
            // matters — UnboundCompanyContextException extends RuntimeException.)
            throw $e;
        } catch (RuntimeException $e) {
            // postBatch is fully transactional, so a failure leaves the batch back in
            // Draft — discard it so it blocks neither a corrected re-import nor the
            // accountant's wizard.
            $this->discardOwnDraftBatch($job, $company->id);

            return $this->rowResults(
                $balanceRows,
                self::WARNING_CODE,
                $e->getMessage(),
                [self::RESULT_KEY => 'error: post_failed'],
            );
        }

        ImportRow::whereIn('id', array_map(
            static fn (array $item): string => $item['row']->id,
            $balanceRows
        ))->update(['imported_entity_id' => $entry->id]);

        return $this->rowResults($balanceRows, '', '', [self::RESULT_KEY => 'ok']);
    }

    /**
     * Idempotent replay of an already-posted job: report 'ok' AND restore the
     * journal-entry linkage, because run() nulls the placeholder for every row of the
     * job before it knows the batch is already Locked. Reporting 'ok' while leaving
     * imported_entity_id NULL would make the linkage a lie on every redelivery.
     *
     * @param  list<array{row: ImportRow, payload: array<string, string>}>  $balanceRows
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function postedResults(array $balanceRows, OpeningBalanceBatch $batch): array
    {
        $entryId = JournalEntry::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $batch->id)
            ->value('id');

        if ($entryId !== null) {
            ImportRow::whereIn('id', array_map(
                static fn (array $entry): string => $entry['row']->id,
                $balanceRows
            ))->update(['imported_entity_id' => $entryId]);
        }

        return $this->rowResults($balanceRows, '', '', [self::RESULT_KEY => 'ok']);
    }

    /**
     * Drop the Draft batch this import job created, so a file that posted nothing
     * leaves no residue blocking the next import or the accountant's UI wizard.
     * Only ever touches a batch keyed to THIS job — never accountant-created work.
     */
    private function discardOwnDraftBatch(ImportJob $job, string $companyId): void
    {
        $batch = $this->findImportBatch($job, $companyId);

        if ($batch === null || ! $batch->isDeletable()) {
            return;
        }

        $this->batchService->deleteBatch($batch);
    }

    /**
     * Every row whose file did not post but which was itself mappable gets the
     * "blocked by another row" result; rejected rows keep their own detail.
     *
     * @param  list<array{row: ImportRow, payload: array<string, string>}>  $balanceRows
     * @param  list<array{row_id: string, code: string, detail: string, results: array<string, string>}>  $rejections
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function blockedResults(array $balanceRows, array $rejections): array
    {
        $rejected = array_column($rejections, 'row_id');
        $blocked = array_values(array_filter(
            $balanceRows,
            static fn (array $entry): bool => ! in_array($entry['row']->id, $rejected, true)
        ));

        return $this->rowResults(
            $blocked,
            self::WARNING_CODE,
            sprintf(
                'the file was not posted: %d row(s) failed validation - correct them and re-import the whole file',
                count($rejections)
            ),
            [self::RESULT_KEY => 'error: file_not_posted'],
        );
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

    /**
     * The batch this import job owns, if any.
     *
     * Scoped to the company and to ACCOUNTING batches, so the in-PHP key match runs
     * over a handful of rows. (The JSONB predicate cannot be expressed as
     * `where('import_file_reference->import_job_id', …)`: Eloquent's `where()` is
     * typed to real model properties, which phpstan level 8 enforces.)
     */
    private function findImportBatch(ImportJob $job, string $companyId): ?OpeningBalanceBatch
    {
        return OpeningBalanceBatch::forCompany($companyId)
            ->ofType(OpeningBatchType::Accounting)
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
     * Per-row validation failures reported by the opening service, mapped back onto
     * the source import rows. Deliberately does NOT skip them in the batch: skipping
     * is what would let a partial file post (see the all-or-nothing note above).
     *
     * @param  list<array{row: ImportRow, payload: array<string, string>}>  $balanceRows
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function rowRejections(OpeningBalanceBatch $batch, array $balanceRows): array
    {
        $rejections = [];
        $batchRows = $batch->rows()->orderBy('row_number')->get()->values();

        foreach ($batchRows as $index => $batchRow) {
            if ($batchRow->status !== OpeningImportRowStatus::Invalid) {
                continue;
            }

            if (! isset($balanceRows[$index])) {
                continue;
            }

            $rejections[] = [
                'row_id' => $balanceRows[$index]['row']->id,
                'code' => self::WARNING_CODE,
                'detail' => implode('; ', $batchRow->getErrorMessages()),
                'results' => [self::RESULT_KEY => 'error: validation_failed'],
            ];
        }

        return $rejections;
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
