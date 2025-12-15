<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use Illuminate\Support\Collection;
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
    private const SCALE = 2;

    public function __construct(
        private readonly OpeningBalanceBatchService $batchService
    ) {}

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

        $rows = $batch->rows()->where('status', '!=', OpeningImportRowStatus::Skipped)->get();
        $validationResults = [];
        $errors = [];

        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($rows as $row) {
            $result = $this->validateRow($row, $batch->company_id);
            $validationResults[$row->id] = $result;

            if ($result['valid']) {
                $totalDebit = bcadd($totalDebit, $result['mapped_data']['debit'] ?? '0.00', self::SCALE);
                $totalCredit = bcadd($totalCredit, $result['mapped_data']['credit'] ?? '0.00', self::SCALE);
            } else {
                $errors[$row->id] = $result['errors'];
            }
        }

        // Apply validation results to rows
        $this->batchService->applyValidationResults($batch, $validationResults);

        // Check if debits equal credits
        $isBalanced = bccomp($totalDebit, $totalCredit, self::SCALE) === 0;

        $validCount = count(array_filter($validationResults, fn (array $r): bool => $r['valid']));
        $invalidCount = count($validationResults) - $validCount;

        // If not balanced, add a batch-level error
        if (! $isBalanced && $validCount > 0) {
            $errors['_batch'] = [
                "Total debits ({$totalDebit}) do not equal total credits ({$totalCredit}). ".
                "Difference: ".bcsub($totalDebit, $totalCredit, self::SCALE),
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
    private function validateRow(OpeningBalanceImportRow $row, string $companyId): array
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
            } else {
                $mappedData['account_id'] = $account->id;
                $mappedData['account_code'] = $account->code;
                $mappedData['account_name'] = $account->name;
            }
        }

        // Validate debit/credit amounts
        $debit = $rawData['debit'] ?? '0.00';
        $credit = $rawData['credit'] ?? '0.00';

        if (! is_numeric($debit) || bccomp((string) $debit, '0.00', self::SCALE) < 0) {
            $errors['debit'] = ['Debit must be a non-negative number'];
        } else {
            $mappedData['debit'] = bcadd('0.00', (string) $debit, self::SCALE);
        }

        if (! is_numeric($credit) || bccomp((string) $credit, '0.00', self::SCALE) < 0) {
            $errors['credit'] = ['Credit must be a non-negative number'];
        } else {
            $mappedData['credit'] = bcadd('0.00', (string) $credit, self::SCALE);
        }

        // Check that at least one of debit/credit is non-zero
        if (empty($errors) && bccomp($mappedData['debit'] ?? '0.00', '0.00', self::SCALE) === 0
            && bccomp($mappedData['credit'] ?? '0.00', '0.00', self::SCALE) === 0) {
            $errors['amount'] = ['Either debit or credit must be non-zero'];
        }

        // Check that both debit and credit are not non-zero simultaneously
        if (empty($errors)
            && bccomp($mappedData['debit'] ?? '0.00', '0.00', self::SCALE) > 0
            && bccomp($mappedData['credit'] ?? '0.00', '0.00', self::SCALE) > 0) {
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

        if (! $batch->canPost()) {
            throw new RuntimeException(
                "Cannot post batch in {$batch->status->label()} status. Batch must be in draft status."
            );
        }

        // Get valid rows only
        $validRows = $batch->rows()
            ->where('status', OpeningImportRowStatus::Valid)
            ->orderBy('row_number')
            ->get();

        if ($validRows->isEmpty()) {
            throw new RuntimeException('No valid rows to post. Please validate the batch first.');
        }

        $company = Company::findOrFail($batch->company_id);

        return DB::transaction(function () use ($batch, $validRows, $company, $userId): JournalEntry {
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
            $totalDebit = '0.00';
            $totalCredit = '0.00';
            $rowEntityMap = [];

            // Create journal lines from valid rows
            foreach ($validRows as $row) {
                $mappedData = $row->mapped_data;

                if (! is_array($mappedData) || ! isset($mappedData['account_id'])) {
                    continue;
                }

                $debit = $mappedData['debit'] ?? '0.00';
                $credit = $mappedData['credit'] ?? '0.00';

                // Only create line if there's a non-zero amount
                if (bccomp($debit, '0.00', self::SCALE) > 0 || bccomp($credit, '0.00', self::SCALE) > 0) {
                    $line = JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $mappedData['account_id'],
                        'partner_id' => null,
                        'debit' => $debit,
                        'credit' => $credit,
                        'description' => $mappedData['description'] ?? 'Opening balance',
                        'line_order' => $lineOrder++,
                    ]);

                    $totalDebit = bcadd($totalDebit, $debit, self::SCALE);
                    $totalCredit = bcadd($totalCredit, $credit, self::SCALE);

                    $rowEntityMap[$row->id] = $line->id;
                }
            }

            // Add OBE offset if needed (shouldn't be needed if properly validated)
            $difference = bcsub($totalDebit, $totalCredit, self::SCALE);
            if (bccomp($difference, '0.00', self::SCALE) !== 0) {
                $obeAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::OpeningBalanceEquity);

                // If debits > credits, credit OBE; if credits > debits, debit OBE
                $obeDebit = bccomp($difference, '0.00', self::SCALE) < 0 ? bcmul($difference, '-1', self::SCALE) : '0.00';
                $obeCredit = bccomp($difference, '0.00', self::SCALE) > 0 ? $difference : '0.00';

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

            // Mark rows as posted
            $this->batchService->markRowsPosted($rowEntityMap);

            // Mark batch as validated (posted in GL context)
            $this->batchService->markBatchValidated($batch, $userId);

            return $entry->load('lines');
        });
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
                'debit' => $mappedData['debit'] ?? '0.00',
                'credit' => $mappedData['credit'] ?? '0.00',
                'description' => $mappedData['description'] ?? '',
            ];
        });

        $totalDebit = $lines->reduce(
            fn (string $carry, array $line): string => bcadd($carry, $line['debit'], self::SCALE),
            '0.00'
        );

        $totalCredit = $lines->reduce(
            fn (string $carry, array $line): string => bcadd($carry, $line['credit'], self::SCALE),
            '0.00'
        );

        return [
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
                'is_balanced' => bccomp($totalDebit, $totalCredit, self::SCALE) === 0,
            ],
        ];
    }

    /**
     * Generate entry number for opening balance journal entry.
     */
    private function generateEntryNumber(string $companyId): string
    {
        $year = date('Y');
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
