<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\OpeningBalancePosted;
use App\Modules\Accounting\Domain\Exceptions\OpeningCashNotFullySeededException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatIntent;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatRepositoryDescriptor;
use App\Shared\Contracts\Treasury\RepositoryOpeningBalanceSeederInterface;
use Carbon\CarbonImmutable;
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
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly RepositoryOpeningBalanceSeederInterface $repositoryOpeningSeeder,
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
            $result = $this->validateRow($row, $batch->tenant_id, $batch->company_id, $scale);
            $validationResults[$row->id] = $result;
        }

        // W4-2: a repository may be named at most ONCE per batch. Two rows
        // seeding the same till would collide on the movement port's
        // per-repository idempotency key at post time (silently keeping only the
        // first float, because a replay is a no-op) — so refuse it here, where
        // the operator can still fix the sheet.
        $this->flagDuplicateRepositoryRows($validationResults);

        foreach ($validationResults as $rowId => $result) {
            if ($result['valid']) {
                $totalDebit = bcadd($totalDebit, $result['mapped_data']['debit'] ?? '0', $scale);
                $totalCredit = bcadd($totalCredit, $result['mapped_data']['credit'] ?? '0', $scale);
            } else {
                $errors[$rowId] = $result['errors'];
            }
        }

        // Apply validation results to rows
        $this->batchService->applyValidationResults($batch, $validationResults);

        // Check if debits equal credits
        $isBalanced = bccomp($totalDebit, $totalCredit, $scale) === 0;

        $validCount = count(array_filter($validationResults, fn (array $r): bool => $r['valid']));
        $invalidCount = count($validationResults) - $validCount;

        // W4-2 / gate r1 F-7 — does the cash this batch debits actually REACH the
        // tills that hang off the accounts it debits? Surfaced here so the
        // operator sees it while the sheet is still fixable; enforced
        // authoritatively in postBatch().
        $coverageGaps = $this->openingCashCoverageGaps(
            $batch->tenant_id,
            $batch->company_id,
            $this->mappedRowsForCoverageFromResults($validationResults),
            $scale,
        );

        if ($coverageGaps !== []) {
            $errors['_batch'] = array_merge($errors['_batch'] ?? [], $coverageGaps);
        }

        // If not balanced, add a batch-level error
        if (! $isBalanced && $validCount > 0) {
            $errors['_batch'] = [
                "Total debits ({$totalDebit}) do not equal total credits ({$totalCredit}). ".
                'Difference: '.bcsub($totalDebit, $totalCredit, $scale),
            ];
        }

        return [
            'valid' => $isBalanced && $invalidCount === 0 && $coverageGaps === [],
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
    private function validateRow(
        OpeningBalanceImportRow $row,
        string $tenantId,
        string $companyId,
        int $scale,
    ): array {
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

        $this->validateRepositoryColumn($rawData, $tenantId, $companyId, $scale, $mappedData, $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'mapped_data' => $mappedData,
        ];
    }

    /**
     * W4-2 — validate the optional `repository_code` column that turns a GL
     * cash/bank opening line into a treasury opening float.
     *
     * Naming a repository is what makes the wizard the ONE sanctioned path to a
     * day-one float: the same row then posts the GL leg AND the repository's
     * opening movement. Every refusal below exists so the two halves can never
     * disagree.
     *
     * @param  array<string, mixed>  $rawData
     * @param  array<string, mixed>  $mappedData
     * @param  array<string, array<int, string>>  $errors
     */
    private function validateRepositoryColumn(
        array $rawData,
        string $tenantId,
        string $companyId,
        int $scale,
        array &$mappedData,
        array &$errors,
    ): void {
        $rawCode = $rawData['repository_code'] ?? null;
        $code = is_string($rawCode) ? trim($rawCode) : '';

        if ($code === '') {
            return;
        }

        $mappedData['repository_code'] = $code;

        $descriptor = $this->repositoryOpeningSeeder->describeByCode($tenantId, $companyId, $code);

        if (! $descriptor instanceof OpeningFloatRepositoryDescriptor) {
            $errors['repository_code'] = ["Payment repository '{$code}' was not found or is inactive."];

            return;
        }

        $mappedData['repository_id'] = $descriptor->id;
        $mappedData['repository_name'] = $descriptor->name;

        // A float ENTERS a till: it is a debit on the asset account. A credit row
        // naming a repository would ask treasury to hold negative opening cash.
        if (bccomp($mappedData['debit'] ?? '0', '0', $scale) <= 0) {
            $errors['repository_code'] = [
                "Payment repository '{$code}' can only be named on a debit line — an opening float ".
                'is money entering the repository.',
            ];

            return;
        }

        // The repository's balance must equal the GL debit that backs it. That
        // is only true if the row debits the repository's OWN cash/bank account.
        if ($descriptor->glAccountId !== null
            && ($mappedData['account_id'] ?? null) !== $descriptor->glAccountId) {
            $expected = Account::query()->whereKey($descriptor->glAccountId)->value('code');
            $errors['repository_code'] = [
                "Payment repository '{$code}' is linked to GL account {$expected}; the opening float ".
                'must be debited to that account so treasury and the ledger agree.',
            ];

            return;
        }

        if ($descriptor->hasMovements) {
            $errors['repository_code'] = [
                "Payment repository '{$code}' already holds money in Treasury, so it cannot receive an ".
                'opening balance. Use a Treasury transfer to move cash into a till that has already traded.',
            ];

            return;
        }

        // Single-currency companies today, but the movement port refuses a
        // currency mismatch outright — surface it as a row error instead of a
        // post-time exception.
        $rowCurrency = Company::query()->whereKey($companyId)->value('currency');
        if (is_string($rowCurrency) && $descriptor->currency !== $rowCurrency) {
            $errors['repository_code'] = [
                "Payment repository '{$code}' is held in {$descriptor->currency}, not the company currency ".
                "{$rowCurrency}.",
            ];
        }
    }

    /**
     * Flag every row naming a repository that another row in the same batch
     * already named.
     *
     * @param  array<string, array{valid: bool, errors: array<string, array<int, string>>, mapped_data: array<string, mixed>}>  $validationResults
     */
    private function flagDuplicateRepositoryRows(array &$validationResults): void
    {
        /** @var array<string, int> $seen */
        $seen = [];

        foreach ($validationResults as $result) {
            $code = $result['mapped_data']['repository_code'] ?? null;
            if (! is_string($code) || $code === '') {
                continue;
            }
            $seen[$code] = ($seen[$code] ?? 0) + 1;
        }

        foreach ($validationResults as $rowId => $result) {
            $code = $result['mapped_data']['repository_code'] ?? null;
            if (! is_string($code) || ($seen[$code] ?? 0) < 2) {
                continue;
            }

            $validationResults[$rowId]['errors']['repository_code'] = [
                "Payment repository '{$code}' is named more than once in this batch. A repository ".
                'receives exactly one opening float.',
            ];
            $validationResults[$rowId]['valid'] = false;
        }
    }

    /**
     * mapped_data of persisted rows, as a LIST the coverage rule can consume.
     *
     * @param  array<int, OpeningBalanceImportRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function mappedRowsForCoverage(array $rows): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            $data = $row->mapped_data;
            if (is_array($data) && isset($data['account_id'])) {
                $mapped[] = $data;
            }
        }

        return $mapped;
    }

    /**
     * The same, from an in-flight validation pass (nothing is persisted yet).
     *
     * @param  array<string, array{valid: bool, errors: array<string, array<int, string>>, mapped_data: array<string, mixed>}>  $validationResults
     * @return list<array<string, mixed>>
     */
    private function mappedRowsForCoverageFromResults(array $validationResults): array
    {
        $mapped = [];

        foreach ($validationResults as $result) {
            if ($result['valid'] && isset($result['mapped_data']['account_id'])) {
                $mapped[] = $result['mapped_data'];
            }
        }

        return $mapped;
    }

    /**
     * Cash this batch debits that would reach NO till at all.
     *
     * THE RULE, and it is deliberately only this one: for every GL account the
     * batch DEBITS that at least one ACTIVE payment repository is linked to, the
     * whole debit on that account must be attributed to repositories by the rows
     * naming them. A four-column legacy sheet attributes nothing, so its entire
     * `Dr 53 1200.000` is a gap — which is precisely the P0 re-opening itself
     * for an operator who has not heard of the new column.
     *
     * WHAT THIS DELIBERATELY DOES NOT REFUSE, and why (gate r1 F-7, PROBE B).
     * A merged `Dr 53 1200.000` row naming only the drawer, on a tenant whose
     * drawer AND safe both hang off `53`, passes: 1200.000 debited, 1200.000
     * attributed. The drawer ends at 1200.000 and the safe at 0.000. The ledger
     * and Treasury still AGREE (Σ tills on 53 == GL Dr 53) — it is a
     * data-entry error about WHICH till holds the money, not a money error.
     *
     * The obvious extra rule — "every repository on the account must be named"
     * — was written and then removed, because it is indistinguishable from a
     * legitimate case: a shop whose safe is genuinely empty on day one. Refusing
     * that would block a correct opening, and there is no affordance today for
     * an operator to declare "this till opens at zero" (a zero row is rejected
     * by validateRow's own amount rule and would post no line anyway). Recorded
     * as a residual instead of guessed at.
     *
     * Accounts no repository is linked to (receivables, inventory, equity, a
     * petty-cash account modelled outside Treasury) are not examined at all.
     *
     * @param  list<array<string, mixed>>  $mappedRows  mapped_data of the rows being posted
     * @return list<string>
     */
    private function openingCashCoverageGaps(
        string $tenantId,
        string $companyId,
        array $mappedRows,
        int $scale,
    ): array {
        /** @var array<string, numeric-string> $debitByAccount */
        $debitByAccount = [];
        /** @var array<string, numeric-string> $attributedByAccount */
        $attributedByAccount = [];
        foreach ($mappedRows as $mapped) {
            $accountId = $mapped['account_id'] ?? null;
            if (! is_string($accountId)) {
                continue;
            }

            // mapped_data is JSONB, so the debit arrives as mixed. Refuse to do
            // bcmath on anything that is not a number rather than casting it to
            // silence the type checker (rule 19): a non-numeric debit is a
            // corrupt staging row, and treating it as '0' would quietly shrink
            // the amount the coverage rule compares against.
            $rawDebit = $mapped['debit'] ?? '0';
            if (! is_string($rawDebit) && ! is_int($rawDebit) && ! is_float($rawDebit)) {
                continue;
            }
            $debit = (string) $rawDebit;
            if (! is_numeric($debit)) {
                continue;
            }
            if (bccomp($debit, '0', $scale) <= 0) {
                continue;
            }

            $debitByAccount[$accountId] = bcadd($debitByAccount[$accountId] ?? '0', $debit, $scale);

            $repositoryId = $mapped['repository_id'] ?? null;
            if (is_string($repositoryId) && $repositoryId !== '') {
                $attributedByAccount[$accountId] = bcadd($attributedByAccount[$accountId] ?? '0', $debit, $scale);
            }
        }

        if ($debitByAccount === []) {
            return [];
        }

        $descriptors = $this->repositoryOpeningSeeder->describeByGlAccounts(
            $tenantId,
            $companyId,
            array_keys($debitByAccount),
        );

        if ($descriptors === []) {
            return [];
        }

        /** @var array<string, list<OpeningFloatRepositoryDescriptor>> $byAccount */
        $byAccount = [];
        foreach ($descriptors as $descriptor) {
            if ($descriptor->glAccountId === null) {
                continue;
            }
            $byAccount[$descriptor->glAccountId][] = $descriptor;
        }

        $gaps = [];

        foreach ($byAccount as $accountId => $repositories) {
            $debit = $debitByAccount[$accountId] ?? '0';
            $attributed = $attributedByAccount[$accountId] ?? '0';
            $code = Account::query()->whereKey($accountId)->value('code');
            $accountLabel = is_string($code) ? $code : $accountId;

            $unattributed = bcsub($debit, $attributed, $scale);
            if (bccomp($unattributed, '0', $scale) > 0) {
                $names = implode(', ', array_map(
                    static fn (OpeningFloatRepositoryDescriptor $r): string => $r->code,
                    $repositories,
                ));
                $gaps[] = "Account {$accountLabel} is debited {$debit} but only {$attributed} is assigned to a ".
                    "payment repository; {$unattributed} would exist in the ledger and in no till ".
                    "(repositories on this account: {$names}).";
            }
        }

        return $gaps;
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

            // gate r1 F-11, narrowed. A batch posts on STATUS alone: postBatch
            // skips non-Valid rows and the OBE offset silently absorbs the
            // imbalance, so a rejected row degrades into "Batch posted
            // successfully" with money missing. That is pre-existing for
            // ordinary GL rows and is left alone — but a rejected row that NAMES
            // A REPOSITORY is new, and it degrades in the worst direction: the
            // till's float vanishes from both the ledger and Treasury while `119`
            // quietly absorbs it, on a write-once batch. Refuse precisely that.
            $rejectedRepositoryRows = $batch->rows()
                ->where('status', OpeningImportRowStatus::Invalid)
                ->get()
                ->filter(static function (OpeningBalanceImportRow $row): bool {
                    $mapped = $row->mapped_data;

                    return is_array($mapped)
                        && is_string($mapped['repository_code'] ?? null)
                        && $mapped['repository_code'] !== '';
                });

            if ($rejectedRepositoryRows->isNotEmpty()) {
                $codes = $rejectedRepositoryRows
                    ->map(static function (OpeningBalanceImportRow $row): string {
                        $mapped = $row->mapped_data;

                        return is_array($mapped) ? (string) ($mapped['repository_code'] ?? '?') : '?';
                    })
                    ->implode(', ');

                throw new RuntimeException(
                    "Cannot post: the opening line(s) for payment repository {$codes} did not validate, so ".
                    'those tills would receive nothing while the rest of the batch posts and the opening '.
                    'balance equity account absorbs the difference. Fix or remove those rows and validate again.'
                );
            }

            // AUTHORITATIVE coverage refusal (gate r1 F-7). Evaluated BEFORE the
            // entry, the lines or any movement exist, so nothing this batch
            // writes can pollute the "already seeded" reading it depends on.
            $coverageGaps = $this->openingCashCoverageGaps(
                $company->tenant_id,
                $company->id,
                $this->mappedRowsForCoverage($validRows->all()),
                $scale,
            );

            if ($coverageGaps !== []) {
                throw new OpeningCashNotFullySeededException($coverageGaps);
            }

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

                    // W4-2 — the treasury half of the SAME row, inside the SAME
                    // transaction as the GL line above. The row's debit IS the
                    // float, and the line above is the GL debit that backs it, so
                    // the repository balance equals the ledger by construction.
                    // Seeded AFTER the line exists: the reconciler's check 2
                    // reconciles a movement against the JE's line on the
                    // repository's own gl_account_id, which is what keeps this
                    // equality enforced for the life of the tenant.
                    $repositoryId = $mappedData['repository_id'] ?? null;
                    if (is_string($repositoryId) && $repositoryId !== '') {
                        $this->repositoryOpeningSeeder->seed(new OpeningFloatIntent(
                            tenantId: $company->tenant_id,
                            companyId: $company->id,
                            repositoryId: $repositoryId,
                            amount: $debit,
                            currency: (string) $company->currency,
                            batchId: $batch->id,
                            occurredAt: CarbonImmutable::parse($batch->cutover_date->toDateString()),
                            journalEntryId: $entry->id,
                            createdBy: $userId,
                        ));
                    }
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
                // W4-2: null on every ordinary GL line; set when this row also
                // seeds a treasury repository's opening float, so the operator
                // sees WHICH till the money lands in before locking the batch.
                'repository_code' => $mappedData['repository_code'] ?? null,
                'repository_name' => $mappedData['repository_name'] ?? null,
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
