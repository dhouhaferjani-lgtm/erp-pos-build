<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\OpeningBalancePosted;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Service for handling GL Opening Balance imports.
 *
 * This service manages the import, validation, and posting of
 * opening account balances from a previous accounting system.
 *
 * Key features:
 * - Validates account codes exist in the chart of accounts
 * - Ensures total debits equal total credits
 * - Creates historical journal entry (excluded from fiscal hash chain)
 * - Uses Opening Balance Equity as the offset account
 */
class AccountingOpeningService
{
    /**
     * Money is stored at the fixed scale 3 (currency decimal(N,3) floor, rule 19):
     * the staging JSONB is canonicalized at 3 and journal_lines.debit/credit are
     * decimal(15,3). This is a STORAGE constant, not a display scale.
     */
    private const MONEY_STORAGE_SCALE = 3;

    public function __construct(
        private readonly OpeningBalanceBatchService $batchService,
        private readonly PartnerControlAccountResolver $controlAccounts,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Monetary scale for a batch, resolved from the OWNING COMPANY'S currency and
     * floored at the STORAGE scale.
     *
     * Two separate hazards, both real:
     *
     * 1. CLAUDE.md rule 19 — a bare no-arg getScale() throws
     *    UnboundCompanyContextException outside a request. This service is reached
     *    from the queued import worker (ProcessImportJob -> finalizeImport ->
     *    AccountingBalancesPhase), which binds TENANT context only and never binds
     *    CompanyContext, so the scale MUST come from the entity.
     *
     * 2. The scale must be the STORAGE scale, not the currency's DISPLAY scale.
     *    Staging canonicalizes money at scale 3
     *    ({@see OpeningBalanceBatchService} MONEY_STORAGE_SCALE, which warns against
     *    exactly this) and journal_lines.debit/credit are decimal(15,3). Computing at
     *    a display scale of 2 would map a staged 100.125 to 100.12 and post 100.120,
     *    with the lost 0.005 silently absorbed by the OBE plug — i.e. into equity.
     *    The floor keeps a hypothetical >3-decimal currency intact while never
     *    dropping below the column's precision.
     */
    private function scaleForBatch(OpeningBalanceBatch $batch): int
    {
        /** @var string|null $currency */
        $currency = Company::query()->whereKey($batch->company_id)->value('currency');

        return $this->moneyScale($currency);
    }

    private function moneyScale(?string $currency): int
    {
        return max(
            $this->scaleResolver->getScaleSafe($currency, self::MONEY_STORAGE_SCALE),
            self::MONEY_STORAGE_SCALE,
        );
    }

    /**
     * Validate all import rows in a GL opening batch.
     *
     * @return array<string, mixed>
     */
    public function validateBatch(OpeningBalanceBatch $batch): array
    {
        if ($batch->type !== OpeningBatchType::Accounting) {
            throw new RuntimeException('This service only handles ACCOUNTING batch types.');
        }

        // A LOCKED (or VALIDATED) batch is sealed: re-validating it would flip POSTED
        // rows back to VALID and replace the mapped_data the SHA-256 batch seal is
        // computed over, leaving a stored hash that no longer verifies.
        if (! $batch->isEditable()) {
            throw new RuntimeException(
                "Cannot validate batch in {$batch->status->label()} status. Only draft batches can be validated."
            );
        }

        $scale = $this->scaleForBatch($batch);
        // POSTED rows are excluded alongside SKIPPED: a posted row's mapped_data is
        // already sealed and its entity already exists.
        $rows = $batch->rows()
            ->whereNotIn('status', [OpeningImportRowStatus::Skipped, OpeningImportRowStatus::Posted])
            ->get();
        $validationResults = [];
        $errors = [];

        $totalDebit = '0';
        $totalCredit = '0';

        foreach ($rows as $row) {
            $result = $this->validateRow($row, $batch->company_id, $scale);
            $validationResults[$row->id] = $result;

            if ($result['valid']) {
                $totalDebit = bcadd($totalDebit, $result['mapped_data']['debit'] ?? '0', $scale);
                $totalCredit = bcadd($totalCredit, $result['mapped_data']['credit'] ?? '0', $scale);
            } else {
                $errors[$row->id] = $result['errors'];
            }
        }

        // Apply validation results to rows
        $this->batchService->applyValidationResults($batch, $validationResults);

        // Check if debits equal credits
        $isBalanced = bccomp($totalDebit, $totalCredit, $scale) === 0;

        $validCount = count(array_filter($validationResults, fn (array $r): bool => $r['valid']));
        $invalidCount = count($validationResults) - $validCount;

        // If not balanced, add a batch-level error
        if (! $isBalanced && $validCount > 0) {
            $errors['_batch'] = [
                "Total debits ({$totalDebit}) do not equal total credits ({$totalCredit}). ".
                'Difference: '.bcsub($totalDebit, $totalCredit, $scale),
            ];
        }

        return [
            'valid' => $isBalanced && $invalidCount === 0,
            'total_rows' => count($rows),
            'valid_rows' => $validCount,
            'invalid_rows' => $invalidCount,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $isBalanced,
            'errors' => $errors,
        ];
    }

    /**
     * Validate a single import row.
     *
     * Expected raw_data format:
     * {
     *   "account_code": "1200",
     *   "debit": "10000.00",
     *   "credit": "0.00",
     *   "description": "Opening balance for Bank Account"
     * }
     *
     * @return array{valid: bool, errors: array<string, array<string>>, mapped_data: array<string, mixed>}
     */
    private function validateRow(OpeningBalanceImportRow $row, string $companyId, int $scale): array
    {
        $rawData = $row->raw_data;
        $errors = [];
        $mappedData = [];

        // Validate required fields
        if (! isset($rawData['account_code']) || $rawData['account_code'] === '') {
            $errors['account_code'] = ['Account code is required'];
        } else {
            // Validate account exists
            $account = Account::forCompany($companyId)
                ->where('code', $rawData['account_code'])
                ->active()
                ->first();

            if ($account === null) {
                $errors['account_code'] = ["Account '{$rawData['account_code']}' not found or inactive"];
            } elseif (($controlBatch = $this->controlAccounts->batchLabelFor($account)) !== null) {
                // W4-4: partner CONTROL accounts are sub-ledger territory and may
                // not be stated here — see PartnerControlAccountResolver for why,
                // and for the ancestor walk that stops the same restatement landing
                // one account down (gate r1 I-4).
                //
                // Refused at validation, before anything posts, so the operator
                // fixes the file rather than unwinding a locked batch. The same
                // refusal runs again in postBatch(): rows that were already `Valid`
                // before this shipped would otherwise post after it (gate r1 I-2).
                $errors['account_code'] = [$this->controlAccounts->refusalMessage($account, $controlBatch)];
            } else {
                $mappedData['account_id'] = $account->id;
                $mappedData['account_code'] = $account->code;
                $mappedData['account_name'] = $account->name;
            }
        }

        // Validate debit/credit amounts
        $debit = $rawData['debit'] ?? '0';
        $credit = $rawData['credit'] ?? '0';

        if (! is_numeric($debit) || bccomp((string) $debit, '0', $scale) < 0) {
            $errors['debit'] = ['Debit must be a non-negative number'];
        } else {
            $mappedData['debit'] = bcadd('0', (string) $debit, $scale);
        }

        if (! is_numeric($credit) || bccomp((string) $credit, '0', $scale) < 0) {
            $errors['credit'] = ['Credit must be a non-negative number'];
        } else {
            $mappedData['credit'] = bcadd('0', (string) $credit, $scale);
        }

        // Check that at least one of debit/credit is non-zero
        if (empty($errors) && bccomp($mappedData['debit'] ?? '0', '0', $scale) === 0
            && bccomp($mappedData['credit'] ?? '0', '0', $scale) === 0) {
            $errors['amount'] = ['Either debit or credit must be non-zero'];
        }

        // Check that both debit and credit are not non-zero simultaneously
        if (empty($errors)
            && bccomp($mappedData['debit'] ?? '0', '0', $scale) > 0
            && bccomp($mappedData['credit'] ?? '0', '0', $scale) > 0) {
            $errors['amount'] = ['A line cannot have both debit and credit - split into two lines'];
        }

        $mappedData['description'] = $rawData['description'] ?? null;

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'mapped_data' => $mappedData,
        ];
    }

    /**
     * Post a validated GL opening batch.
     *
     * Creates a historical journal entry with:
     * - is_historical = true (excluded from fiscal hash chain)
     * - source_type = 'opening_balance'
     * - source_id = batch.id
     * - One line per valid import row
     * - OBE offset line if debits != credits (shouldn't happen if validated)
     *
     * @throws RuntimeException If batch is not validated or has errors
     */
    public function postBatch(OpeningBalanceBatch $batch, string $userId): JournalEntry
    {
        if ($batch->type !== OpeningBatchType::Accounting) {
            throw new RuntimeException('This service only handles ACCOUNTING batch types.');
        }

        // Cheap fast-fail on the obviously-wrong status. The AUTHORITATIVE guard is
        // the locked re-read inside the transaction below — this one reads an
        // in-memory model that a concurrent request may already have superseded.
        if (! $batch->canPost()) {
            throw new RuntimeException(
                "Cannot post batch in {$batch->status->label()} status. Batch must be in draft status."
            );
        }

        $company = Company::findOrFail($batch->company_id);
        $scale = $this->moneyScale($company->currency);
        $batchId = $batch->id;
        $postedRowCount = 0;

        $entry = DB::transaction(function () use ($batchId, $company, $userId, $scale, &$postedRowCount): JournalEntry {
            // FIRST statement: re-read the batch FOR UPDATE and evaluate the guard on
            // the fresh row, then read the rows under that lock.
            $batch = $this->batchService->lockBatchForPosting($batchId);

            $validRows = $batch->rows()
                ->where('status', OpeningImportRowStatus::Valid)
                ->orderBy('row_number')
                ->get();

            if ($validRows->isEmpty()) {
                throw new RuntimeException('No valid rows to post. Please validate the batch first.');
            }

            $postedRowCount = $validRows->count();

            $entryNumber = $this->generateEntryNumber($company->id);

            // Create historical journal entry
            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'entry_number' => $entryNumber,
                'entry_date' => $batch->cutover_date,
                'description' => "GL Opening Balance - {$batch->name}",
                'status' => JournalEntryStatus::Posted, // Directly posted (no hash chain)
                'source_type' => 'opening_balance',
                'source_id' => $batch->id,
                'is_historical' => true, // Skip fiscal hash chain
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            $lineOrder = 0;
            $totalDebit = '0';
            $totalCredit = '0';
            $rowEntityMap = [];

            // Create journal lines from valid rows
            foreach ($validRows as $row) {
                $mappedData = $row->mapped_data;

                if (! is_array($mappedData) || ! isset($mappedData['account_id'])) {
                    continue;
                }

                // Gate r1 I-2: validation is not the last word. postBatch() posts
                // whatever is already `Valid`, so a batch validated BEFORE the
                // control-account rule shipped would post a 411/401 line AFTER it
                // shipped and double the control account against the AR/AP openings.
                // Re-assert on the row that is actually about to be written.
                $postingAccount = Account::query()->whereKey($mappedData['account_id'])->first();

                if ($postingAccount !== null
                    && ($controlBatch = $this->controlAccounts->batchLabelFor($postingAccount)) !== null) {
                    throw new RuntimeException($this->controlAccounts->refusalMessage($postingAccount, $controlBatch));
                }

                $debit = $mappedData['debit'] ?? '0';
                $credit = $mappedData['credit'] ?? '0';

                // Only create line if there's a non-zero amount
                if (bccomp($debit, '0', $scale) > 0 || bccomp($credit, '0', $scale) > 0) {
                    $line = JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $mappedData['account_id'],
                        'partner_id' => null,
                        'debit' => $debit,
                        'credit' => $credit,
                        'description' => $mappedData['description'] ?? 'Opening balance',
                        'line_order' => $lineOrder++,
                    ]);

                    $totalDebit = bcadd($totalDebit, $debit, $scale);
                    $totalCredit = bcadd($totalCredit, $credit, $scale);

                    $rowEntityMap[$row->id] = $line->id;
                }
            }

            // Add OBE offset if needed (shouldn't be needed if properly validated)
            $difference = bcsub($totalDebit, $totalCredit, $scale);
            if (bccomp($difference, '0', $scale) !== 0) {
                $obeAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::OpeningBalanceEquity);

                // If debits > credits, credit OBE; if credits > debits, debit OBE
                $obeDebit = bccomp($difference, '0', $scale) < 0 ? bcmul($difference, '-1', $scale) : '0';
                $obeCredit = bccomp($difference, '0', $scale) > 0 ? $difference : '0';

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $obeAccount->id,
                    'partner_id' => null,
                    'debit' => $obeDebit,
                    'credit' => $obeCredit,
                    'description' => 'Opening Balance Equity offset',
                    'line_order' => $lineOrder,
                ]);
            }

            // Mark batch as validated BEFORE marking rows posted
            // (markBatchValidated checks valid row count, which would be 0 after markRowsPosted)
            $this->batchService->markBatchValidated($batch, $userId);

            // Mark rows as posted
            $this->batchService->markRowsPosted($rowEntityMap);

            // Lock batch for immutability (Validated → Locked).
            // markBatchValidated already refreshed the model onto the claimed row.
            $this->batchService->lockBatch($batch, $userId);

            return $entry->load('lines');
        });

        // Dispatch event after transaction succeeds
        $totalDebit = '0';
        $totalCredit = '0';

        foreach ($entry->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, $scale);
            $totalCredit = bcadd($totalCredit, $line->credit, $scale);
        }

        // Total amount is the greater of debit/credit (they should be equal)
        $totalAmount = bccomp($totalDebit, $totalCredit, $scale) >= 0 ? $totalDebit : $totalCredit;

        event(new OpeningBalancePosted(
            batchId: $batch->id,
            tenantId: $company->tenant_id,
            companyId: $company->id,
            entryCount: $postedRowCount,
            totalAmount: $totalAmount,
            postedAt: now()->toIso8601String(),
        ));

        return $entry;
    }

    /**
     * Get a preview of what will be posted.
     *
     * @return array<string, mixed>
     */
    public function getPostPreview(OpeningBalanceBatch $batch): array
    {
        if ($batch->type !== OpeningBatchType::Accounting) {
            throw new RuntimeException('This service only handles ACCOUNTING batch types.');
        }

        $scale = $this->scaleForBatch($batch);
        $validRows = $batch->rows()
            ->where('status', OpeningImportRowStatus::Valid)
            ->orderBy('row_number')
            ->get();

        $lines = $validRows->map(function (OpeningBalanceImportRow $row): array {
            $mappedData = $row->mapped_data;

            return [
                'row_number' => $row->row_number,
                'account_code' => $mappedData['account_code'] ?? 'N/A',
                'account_name' => $mappedData['account_name'] ?? 'N/A',
                'debit' => $mappedData['debit'] ?? '0',
                'credit' => $mappedData['credit'] ?? '0',
                'description' => $mappedData['description'] ?? '',
            ];
        });

        $totalDebit = $lines->reduce(
            fn (string $carry, array $line): string => bcadd($carry, $line['debit'], $scale),
            '0'
        );

        $totalCredit = $lines->reduce(
            fn (string $carry, array $line): string => bcadd($carry, $line['credit'], $scale),
            '0'
        );

        return [
            // N-3 discriminator: the three preview variants are structurally
            // different (ACCOUNTING has `entry`, the others have `batch`), so the
            // frontend needs a top-level tag to narrow the union on. Nothing else
            // in this payload may be assumed common across variants.
            'batch_type' => OpeningBatchType::Accounting->value,
            'entry' => [
                'entry_date' => $batch->cutover_date->toDateString(),
                'description' => "GL Opening Balance - {$batch->name}",
                'is_historical' => true,
                'source_type' => 'opening_balance',
            ],
            'lines' => $lines,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'is_balanced' => bccomp($totalDebit, $totalCredit, $scale) === 0,
            ],
        ];
    }

    /**
     * Generate a concurrency-safe OB-{year}-{seq} entry number.
     *
     * Must be called INSIDE an open DB::transaction. On PostgreSQL, acquires a
     * transaction-scoped advisory lock keyed on (companyId, year) to close the
     * TOCTOU race between the read-max and the insert: without it two concurrent
     * posts both read the same max and both mint OB-{year}-000001, and the loser
     * fails on the journal_entries (tenant_id, entry_number) unique index — which
     * stays as the second line of defence. On SQLite (test runner) the advisory
     * lock is skipped: concurrency is not meaningful there.
     *
     * Same pattern as the corrected sibling generateOpeningEntryNumber() in the
     * Inventory module's OpeningBalancePostingService (named, not imported: module
     * boundaries are enforced by deptrac and a docblock is not a dependency).
     */
    private function generateEntryNumber(string $companyId): string
    {
        $year = date('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ["gl-ob-seq:{$companyId}:{$year}"],
            );
        }

        $lastEntry = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('entry_number', 'like', "OB-{$year}-%")
            ->orderByDesc('entry_number')
            ->first();

        if ($lastEntry !== null) {
            $lastNumber = (int) substr($lastEntry->entry_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('OB-%s-%06d', $year, $nextNumber);
    }
}
