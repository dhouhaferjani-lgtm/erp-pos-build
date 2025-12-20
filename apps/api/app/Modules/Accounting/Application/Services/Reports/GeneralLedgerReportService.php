<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GeneralLedgerReportService
 *
 * Application service for generating General Ledger (GL) reports.
 *
 * The General Ledger is a detailed record of all accounting transactions
 * for one or more accounts over a specified period. Unlike the Trial Balance
 * (which shows account totals), the GL shows individual journal entries with
 * running balances.
 *
 * Key Features:
 * - Point-in-time snapshots or date range queries
 * - Running balance calculation (cumulative debit - credit)
 * - Opening balance calculation (all transactions before date range)
 * - Account filtering (single account or all accounts)
 * - Partner/subledger filtering (for customer/supplier statements)
 * - Source document tracking (invoice, payment, etc.)
 *
 * Use Cases:
 * 1. Account reconciliation (bank accounts, AP/AR)
 * 2. Audit trail verification
 * 3. Transaction history for specific accounts
 * 4. Customer/supplier statements (partner_id filtering)
 *
 * Performance Notes:
 * - For large datasets (100K+ journal lines), this query can be slow
 * - Recommended indexes:
 *   - journal_entries(company_id, status, entry_date)
 *   - journal_lines(account_id, journal_entry_id)
 *   - journal_lines(partner_id) for subledger queries
 * - Consider pagination for web display (50-100 lines per page)
 * - Use caching for historical periods (already closed)
 *
 * Architectural Notes:
 * - Application layer service (orchestrates domain logic)
 * - No business rules (pure query/aggregation)
 * - Returns arrays (not DTOs) for flexibility
 * - Controller transforms to DTOs for API response
 *
 * @package App\Modules\Accounting\Application\Services\Reports
 */
class GeneralLedgerReportService
{
    /**
     * Decimal scale for bcmath operations.
     *
     * Using 4 decimal places for precision in financial calculations.
     * This prevents rounding errors in cumulative balance calculations.
     */
    private const DECIMAL_SCALE = 4;

    /**
     * Generate a General Ledger report with pagination support.
     *
     * Returns a detailed list of journal entries with running balances for
     * the specified account(s) and date range.
     *
     * Algorithm:
     * 1. Calculate opening balance (transactions before date_from + offset)
     * 2. Query journal lines in date range with LIMIT/OFFSET for pagination
     * 3. Calculate running balance for each transaction (starting from page opening balance)
     * 4. Calculate total count for pagination metadata
     * 5. Return formatted array with metadata
     *
     * IMPORTANT: For pagination to work correctly, the opening_balance must account
     * for ALL transactions before the current page (including prior pages in the result set).
     *
     * @param string $companyId Company UUID for multi-tenancy
     * @param string|null $accountId Optional account UUID (null = all accounts)
     * @param Carbon|null $dateFrom Optional start date (null = from beginning)
     * @param Carbon|null $dateTo Optional end date (null = to today)
     * @param string|null $partnerId Optional partner UUID for subledger filtering
     * @param int $offset Number of records to skip for pagination (default: 0)
     * @param int|null $limit Max records to return (null = all records, no pagination)
     * @return array{
     *     opening_balance: numeric-string,
     *     closing_balance: numeric-string,
     *     total_debits: numeric-string,
     *     total_credits: numeric-string,
     *     lines: list<array{
     *         id: string,
     *         date: string,
     *         entry_number: string,
     *         description: string,
     *         account_code: string,
     *         account_name: string,
     *         partner_name: string|null,
     *         debit: numeric-string,
     *         credit: numeric-string,
     *         balance: numeric-string,
     *         source_type: string|null,
     *         source_id: string|null
     *     }>,
     *     date_from: string|null,
     *     date_to: string|null,
     *     account_filter: string|null,
     *     partner_filter: string|null,
     *     total_count: int
     * }
     */
    public function generate(
        string $companyId,
        ?string $accountId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?string $partnerId = null,
        int $offset = 0,
        ?int $limit = null
    ): array {
        // Normalize dates
        $dateFrom = $dateFrom?->startOfDay();
        $dateTo = $dateTo?->endOfDay() ?? Carbon::now()->endOfDay();

        // Step 1: Get total count for pagination metadata
        $totalCount = $this->getTotalCount(
            $companyId,
            $accountId,
            $dateFrom,
            $dateTo,
            $partnerId
        );

        // Step 2: Calculate opening balance for THIS PAGE
        // This includes: (1) all transactions before date_from + (2) transactions in date range before current page
        $pageOpeningBalance = $this->calculatePageOpeningBalance(
            $companyId,
            $accountId,
            $dateFrom,
            $dateTo,
            $partnerId,
            $offset
        );

        // Step 3: Query journal lines for current page only (with LIMIT/OFFSET)
        $lines = $this->queryJournalLines(
            $companyId,
            $accountId,
            $dateFrom,
            $dateTo,
            $partnerId,
            $offset,
            $limit
        );

        // Step 4: Calculate running balances starting from page opening balance
        $processedLines = $this->calculateRunningBalances($lines, $pageOpeningBalance);

        // Step 5: Calculate totals for THIS PAGE only
        $totalDebits = '0.0000';
        $totalCredits = '0.0000';
        foreach ($processedLines as $line) {
            $totalDebits = bcadd($totalDebits, $line['debit'], self::DECIMAL_SCALE);
            $totalCredits = bcadd($totalCredits, $line['credit'], self::DECIMAL_SCALE);
        }

        $closingBalance = count($processedLines) > 0
            ? $processedLines[count($processedLines) - 1]['balance']
            : $pageOpeningBalance;

        return [
            'opening_balance' => $pageOpeningBalance,
            'closing_balance' => $closingBalance,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'lines' => $processedLines,
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'account_filter' => $accountId,
            'partner_filter' => $partnerId,
            'total_count' => $totalCount,
        ];
    }

    /**
     * Get total count of transactions in the date range.
     *
     * Used for pagination metadata.
     *
     * @param string $companyId Company UUID
     * @param string|null $accountId Optional account UUID filter
     * @param Carbon|null $dateFrom Optional start date
     * @param Carbon $dateTo End date
     * @param string|null $partnerId Optional partner UUID filter
     * @return int Total number of transactions matching filters
     */
    private function getTotalCount(
        string $companyId,
        ?string $accountId,
        ?Carbon $dateFrom,
        Carbon $dateTo,
        ?string $partnerId
    ): int {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', JournalEntryStatus::Posted->value)
            ->where('je.entry_date', '<=', $dateTo->toDateString());

        if ($dateFrom !== null) {
            $query->where('je.entry_date', '>=', $dateFrom->toDateString());
        }

        if ($accountId !== null) {
            $query->where('jl.account_id', $accountId);
        }

        if ($partnerId !== null) {
            $query->where('jl.partner_id', $partnerId);
        }

        return $query->count();
    }

    /**
     * Calculate the opening balance for a specific page.
     *
     * For pagination to work correctly, the opening balance must include:
     * 1. All transactions before date_from (if specified)
     * 2. Transactions in the date range that appear BEFORE the current page (offset)
     *
     * This ensures that the running balance on each page is mathematically correct
     * and continuous across pages.
     *
     * Example:
     * - Total opening balance (before date_from): $1,000
     * - Page 1 (offset 0): opening = $1,000
     * - Page 2 (offset 50): opening = $1,000 + sum(page 1 transactions)
     * - Page 3 (offset 100): opening = $1,000 + sum(page 1 + page 2 transactions)
     *
     * SQL Strategy:
     * - SUM all journal lines before date_from (historical opening balance)
     * - SUM journal lines in date range but before current offset
     * - Total = historical + prior_pages
     *
     * @param string $companyId Company UUID
     * @param string|null $accountId Optional account UUID filter
     * @param Carbon|null $dateFrom Start date for the report
     * @param Carbon $dateTo End date for the report
     * @param string|null $partnerId Optional partner UUID filter
     * @param int $offset Number of records being skipped (for pagination)
     * @return numeric-string Opening balance for this page
     */
    private function calculatePageOpeningBalance(
        string $companyId,
        ?string $accountId,
        ?Carbon $dateFrom,
        Carbon $dateTo,
        ?string $partnerId,
        int $offset
    ): string {
        // Part 1: Calculate historical opening balance (all transactions before date_from)
        $historicalBalance = '0.0000';
        if ($dateFrom !== null) {
            $query = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.company_id', $companyId)
                ->where('je.status', JournalEntryStatus::Posted->value)
                ->where('je.entry_date', '<', $dateFrom->toDateString());

            if ($accountId !== null) {
                $query->where('jl.account_id', $accountId);
            }

            if ($partnerId !== null) {
                $query->where('jl.partner_id', $partnerId);
            }

            $result = $query->selectRaw(
                'COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) as balance'
            )->first();

            $historicalBalance = bcadd('0.0000', (string) ($result->balance ?? 0), self::DECIMAL_SCALE);
        }

        // Part 2: Calculate balance from prior pages (transactions in date range before current offset)
        $priorPagesBalance = '0.0000';
        if ($offset > 0) {
            $priorQuery = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->where('je.company_id', $companyId)
                ->where('je.status', JournalEntryStatus::Posted->value)
                ->where('je.entry_date', '<=', $dateTo->toDateString());

            if ($dateFrom !== null) {
                $priorQuery->where('je.entry_date', '>=', $dateFrom->toDateString());
            }

            if ($accountId !== null) {
                $priorQuery->where('jl.account_id', $accountId);
            }

            if ($partnerId !== null) {
                $priorQuery->where('jl.partner_id', $partnerId);
            }

            // Get the prior pages' transactions in chronological order
            $priorTransactions = $priorQuery
                ->select('jl.debit', 'jl.credit')
                ->orderBy('je.entry_date', 'asc')
                ->orderBy('je.entry_number', 'asc')
                ->orderBy('jl.line_order', 'asc')
                ->limit($offset)
                ->get();

            // Sum up the prior pages' debits and credits
            foreach ($priorTransactions as $transaction) {
                $priorPagesBalance = bcadd($priorPagesBalance, (string) $transaction->debit, self::DECIMAL_SCALE);
                $priorPagesBalance = bcsub($priorPagesBalance, (string) $transaction->credit, self::DECIMAL_SCALE);
            }
        }

        // Total opening balance = historical + prior pages
        return bcadd($historicalBalance, $priorPagesBalance, self::DECIMAL_SCALE);
    }

    /**
     * Query journal lines with all related data.
     *
     * Returns a collection of journal line records with joined data from:
     * - journal_entries (entry_number, entry_date, description, source info)
     * - accounts (code, name)
     * - partners (name) - optional
     *
     * SQL Strategy:
     * - INNER JOIN journal_entries (only posted entries with valid dates)
     * - INNER JOIN accounts (for account details)
     * - LEFT JOIN partners (may be null for GL entries without subledger)
     * - ORDER BY entry_date, entry_number, line_order (chronological with consistent ordering)
     * - LIMIT/OFFSET for pagination (optional)
     *
     * Performance Notes:
     * - With pagination (limit + offset), this query is efficient
     * - Without pagination, can return large result sets (100K+ rows)
     * - Indexes required:
     *   - journal_entries(company_id, status, entry_date)
     *   - journal_lines(account_id, journal_entry_id)
     *   - journal_lines(partner_id) if using partner filter
     *
     * @param string $companyId Company UUID
     * @param string|null $accountId Optional account UUID filter
     * @param Carbon|null $dateFrom Optional start date
     * @param Carbon $dateTo End date (default: today)
     * @param string|null $partnerId Optional partner UUID filter
     * @param int $offset Number of records to skip (for pagination)
     * @param int|null $limit Max records to return (null = no limit)
     * @return \Illuminate\Support\Collection<int, object{
     *     line_id: string,
     *     entry_date: string,
     *     entry_number: string,
     *     entry_description: string|null,
     *     line_description: string|null,
     *     account_code: string,
     *     account_name: string,
     *     partner_name: string|null,
     *     debit: numeric-string,
     *     credit: numeric-string,
     *     source_type: string|null,
     *     source_id: string|null
     * }>
     */
    private function queryJournalLines(
        string $companyId,
        ?string $accountId,
        ?Carbon $dateFrom,
        Carbon $dateTo,
        ?string $partnerId,
        int $offset = 0,
        ?int $limit = null
    ): \Illuminate\Support\Collection {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->leftJoin('partners as p', 'p.id', '=', 'jl.partner_id')
            ->where('je.company_id', $companyId)
            ->where('je.status', JournalEntryStatus::Posted->value)
            ->where('je.entry_date', '<=', $dateTo->toDateString());

        // Filter by date_from if specified
        if ($dateFrom !== null) {
            $query->where('je.entry_date', '>=', $dateFrom->toDateString());
        }

        // Filter by account if specified
        if ($accountId !== null) {
            $query->where('jl.account_id', $accountId);
        }

        // Filter by partner if specified (for subledger)
        if ($partnerId !== null) {
            $query->where('jl.partner_id', $partnerId);
        }

        $query = $query
            ->select([
                'jl.id as line_id',
                'je.entry_date',
                'je.entry_number',
                'je.description as entry_description',
                'jl.description as line_description',
                'a.code as account_code',
                'a.name as account_name',
                'p.name as partner_name',
                'jl.debit',
                'jl.credit',
                'je.source_type',
                'je.source_id',
            ])
            ->orderBy('je.entry_date', 'asc')
            ->orderBy('je.entry_number', 'asc')
            ->orderBy('jl.line_order', 'asc');

        // Apply pagination if limit is specified
        if ($limit !== null) {
            $query->limit($limit);
        }

        // Always apply offset (even if 0)
        $query->offset($offset);

        return $query->get();
    }

    /**
     * Calculate running balances for journal lines.
     *
     * Iterates through journal lines in chronological order, calculating
     * the cumulative balance after each transaction.
     *
     * Balance Calculation:
     * - balance = previous_balance + debit - credit
     * - Uses bcmath for precision (avoids floating-point errors)
     * - Maintains 4 decimal places throughout
     *
     * Algorithm:
     * 1. Start with opening balance
     * 2. For each transaction:
     *    - Add debit, subtract credit
     *    - Record running balance
     * 3. Return array with balance column added
     *
     * Example:
     * ```
     * Opening Balance: 1000.00
     * Transaction 1: +500.00 debit  → Balance: 1500.00
     * Transaction 2: -200.00 credit → Balance: 1300.00
     * Transaction 3: +100.00 debit  → Balance: 1400.00
     * ```
     *
     * @param \Illuminate\Support\Collection $lines Raw journal lines from query
     * @param numeric-string $openingBalance Starting balance before transactions
     * @return list<array{
     *     id: string,
     *     date: string,
     *     entry_number: string,
     *     description: string,
     *     account_code: string,
     *     account_name: string,
     *     partner_name: string|null,
     *     debit: numeric-string,
     *     credit: numeric-string,
     *     balance: numeric-string,
     *     source_type: string|null,
     *     source_id: string|null
     * }>
     */
    private function calculateRunningBalances(
        \Illuminate\Support\Collection $lines,
        string $openingBalance
    ): array {
        $runningBalance = $openingBalance;
        $processedLines = [];

        foreach ($lines as $line) {
            // Calculate new balance: balance = balance + debit - credit
            $runningBalance = bcadd($runningBalance, $line->debit, self::DECIMAL_SCALE);
            $runningBalance = bcsub($runningBalance, $line->credit, self::DECIMAL_SCALE);

            // Use line description if available, otherwise use entry description
            $description = $line->line_description ?? $line->entry_description ?? '';

            $processedLines[] = [
                'id' => $line->line_id,
                'date' => $line->entry_date,
                'entry_number' => $line->entry_number,
                'description' => $description,
                'account_code' => $line->account_code,
                'account_name' => $line->account_name,
                'partner_name' => $line->partner_name,
                'debit' => number_format((float) $line->debit, 4, '.', ''),
                'credit' => number_format((float) $line->credit, 4, '.', ''),
                'balance' => $runningBalance,
                'source_type' => $line->source_type,
                'source_id' => $line->source_id,
            ];
        }

        return $processedLines;
    }

    /**
     * Get account details for the ledger header.
     *
     * Returns account information to display in the report header
     * (e.g., "General Ledger - Account 100: Cash").
     *
     * @param string $accountId Account UUID
     * @return array{code: string, name: string, type: string}|null
     */
    public function getAccountDetails(string $accountId): ?array
    {
        $account = Account::find($accountId);

        if ($account === null) {
            return null;
        }

        return [
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
        ];
    }
}
