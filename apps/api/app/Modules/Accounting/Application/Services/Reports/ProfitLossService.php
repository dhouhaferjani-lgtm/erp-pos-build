<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Services\AccountHierarchyService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ProfitLossService
 *
 * Application service for generating Profit & Loss (P&L) reports,
 * also known as Income Statements.
 *
 * The Profit & Loss report shows a company's revenues and expenses over
 * a period of time, culminating in net income (profit) or net loss.
 *
 * Report Structure:
 * ```
 * Revenue
 *   Sales Revenue                  $50,000
 *   Service Revenue                $20,000
 *   Total Revenue                  $70,000
 *
 * Expenses
 *   Cost of Goods Sold            ($30,000)
 *   Operating Expenses            ($15,000)
 *   Total Expenses                ($45,000)
 *
 * Net Income                        $25,000
 * ```
 *
 * Key Features:
 * - Period-based reporting (date range required)
 * - Hierarchical account display with subtotals
 * - Revenue vs Expense classification
 * - Net income calculation (revenue - expenses)
 * - Support for zero-balance filtering
 *
 * Accounting Rules:
 * - Revenue accounts: CREDIT balance = income (credit - debit)
 * - Expense accounts: DEBIT balance = expense (debit - credit)
 * - Net Income = Total Revenue - Total Expenses
 *
 * Use Cases:
 * 1. Monthly/quarterly/annual financial performance review
 * 2. Budget vs actual analysis
 * 3. Profitability assessment
 * 4. Tax reporting (annual P&L)
 * 5. Investor/stakeholder reporting
 *
 * Performance Notes:
 * - Generally fast (revenue/expense accounts are fewer than assets/liabilities)
 * - Recommended indexes:
 *   - journal_entries(company_id, status, entry_date)
 *   - journal_lines(account_id)
 *   - accounts(company_id, type, is_active)
 * - Consider caching for closed fiscal periods
 *
 * Architectural Notes:
 * - Application layer service (orchestrates domain logic)
 * - Uses AccountHierarchyService for tree building
 * - Returns arrays (not DTOs) for flexibility
 * - Controller transforms to DTOs for API response
 */
class ProfitLossService
{
    /**
     * Decimal scale for bcmath operations.
     *
     * Using 4 decimal places for precision in financial calculations.
     */
    private const DECIMAL_SCALE = 4;

    /**
     * Zero threshold for filtering accounts.
     *
     * Accounts with balance less than this are considered zero.
     */
    private const ZERO_THRESHOLD = '0.0001';

    public function __construct(
        private readonly AccountHierarchyService $hierarchyService
    ) {}

    /**
     * Generate a Profit & Loss report.
     *
     * Returns revenue and expense accounts with their balances over the
     * specified period, along with calculated totals and net income.
     *
     * Algorithm:
     * 1. Query revenue account balances (credit - debit) for period
     * 2. Query expense account balances (debit - credit) for period
     * 3. Build hierarchical trees for both revenue and expenses
     * 4. Calculate totals and net income
     * 5. Return formatted array
     *
     * @param  string  $companyId  Company UUID for multi-tenancy
     * @param  Carbon  $dateFrom  Start date of the period (inclusive)
     * @param  Carbon  $dateTo  End date of the period (inclusive)
     * @param  bool  $includeZeroBalances  Whether to include accounts with zero balance
     * @param  bool  $includeHierarchy  Whether to build hierarchical structure
     * @return array{
     *     revenue: list<array{
     *         account_code: string,
     *         account_name: string,
     *         account_type: string,
     *         amount: string,
     *         level: int,
     *         is_parent: bool
     *     }>,
     *     expenses: list<array{
     *         account_code: string,
     *         account_name: string,
     *         account_type: string,
     *         amount: string,
     *         level: int,
     *         is_parent: bool
     *     }>,
     *     total_revenue: numeric-string,
     *     total_expenses: numeric-string,
     *     net_income: numeric-string,
     *     date_from: string,
     *     date_to: string
     * }
     */
    public function generate(
        string $companyId,
        Carbon $dateFrom,
        Carbon $dateTo,
        bool $includeZeroBalances = false,
        bool $includeHierarchy = true
    ): array {
        // Normalize dates
        $dateFrom = $dateFrom->startOfDay();
        $dateTo = $dateTo->endOfDay();

        // Step 1: Query revenue account balances
        $revenueBalances = $this->queryAccountBalances(
            $companyId,
            AccountType::Revenue,
            $dateFrom,
            $dateTo
        );

        // Step 2: Query expense account balances
        $expenseBalances = $this->queryAccountBalances(
            $companyId,
            AccountType::Expense,
            $dateFrom,
            $dateTo
        );

        // Step 3: Load Account models with calculated balances
        $revenueAccounts = $this->loadAccountsWithBalances($revenueBalances);
        $expenseAccounts = $this->loadAccountsWithBalances($expenseBalances);

        // Step 4: Build hierarchical structure or flat list
        if ($includeHierarchy) {
            $revenueLines = $this->buildHierarchicalReport($revenueAccounts, $includeZeroBalances);
            $expenseLines = $this->buildHierarchicalReport($expenseAccounts, $includeZeroBalances);
        } else {
            $revenueLines = $this->buildFlatReport($revenueAccounts, $includeZeroBalances);
            $expenseLines = $this->buildFlatReport($expenseAccounts, $includeZeroBalances);
        }

        // Step 5: Calculate totals
        $totalRevenue = $this->calculateTotal($revenueLines);
        $totalExpenses = $this->calculateTotal($expenseLines);
        $netIncome = bcsub($totalRevenue, $totalExpenses, self::DECIMAL_SCALE);

        return [
            'revenue' => $revenueLines,
            'expenses' => $expenseLines,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'net_income' => $netIncome,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
        ];
    }

    /**
     * Query account balances for a specific account type and date range.
     *
     * For revenue accounts: amount = credit - debit (credit increases revenue)
     * For expense accounts: amount = debit - credit (debit increases expenses)
     *
     * SQL Strategy:
     * - JOIN journal_lines with journal_entries (posted only)
     * - Filter by company_id, account type, and date range
     * - GROUP BY account to aggregate balances
     * - Calculate balance based on account type
     *
     * @param  string  $companyId  Company UUID
     * @param  AccountType  $accountType  Type of accounts to query (revenue or expense)
     * @param  Carbon  $dateFrom  Start date
     * @param  Carbon  $dateTo  End date
     * @return Collection<int, \stdClass>
     */
    private function queryAccountBalances(
        string $companyId,
        AccountType $accountType,
        Carbon $dateFrom,
        Carbon $dateTo
    ): Collection {
        // Build the balance calculation based on account type
        // Revenue: credit - debit (credit increases revenue)
        // Expense: debit - credit (debit increases expenses)
        $balanceFormula = $accountType === AccountType::Revenue
            ? 'COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0)'
            : 'COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)';

        return DB::table('accounts as a')
            ->leftJoin('journal_lines as jl', function ($join) use ($dateFrom, $dateTo) {
                $join->on('jl.account_id', '=', 'a.id')
                    ->join('journal_entries as je', function ($entryJoin) use ($dateFrom, $dateTo) {
                        $entryJoin->on('je.id', '=', 'jl.journal_entry_id')
                            ->where('je.status', '=', JournalEntryStatus::Posted->value)
                            ->where('je.entry_date', '>=', $dateFrom->toDateString())
                            ->where('je.entry_date', '<=', $dateTo->toDateString());
                    });
            })
            ->where('a.company_id', $companyId)
            ->where('a.type', $accountType->value)
            ->where('a.is_active', true)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.parent_id')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.type',
                'a.parent_id',
                DB::raw("{$balanceFormula} as balance"),
            ])
            ->orderBy('a.code', 'asc')
            ->get();
    }

    /**
     * Load Account models and attach calculated balances.
     *
     * @param  Collection<int, \stdClass>  $balances  Collection from queryAccountBalances
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    private function loadAccountsWithBalances(Collection $balances): \Illuminate\Database\Eloquent\Collection
    {
        $accountIds = $balances->pluck('account_id')->toArray();

        if (empty($accountIds)) {
            return new \Illuminate\Database\Eloquent\Collection([]);
        }

        // Load Account models
        $accounts = Account::whereIn('id', $accountIds)->get()->keyBy('id');

        // Attach balances to Account models and collect into Eloquent Collection
        $accountsWithBalances = [];
        foreach ($balances as $balanceData) {
            $account = $accounts->get($balanceData->account_id);
            if ($account !== null) {
                // Attach calculated balance as a dynamic property
                $account->setAttribute('calculated_balance', (string) $balanceData->balance);
                $accountsWithBalances[] = $account;
            }
        }

        return new \Illuminate\Database\Eloquent\Collection($accountsWithBalances);
    }

    /**
     * Build hierarchical report with account tree and subtotals.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Account>  $accounts  Accounts with calculated balances
     * @param  bool  $includeZeroBalances  Whether to include zero-balance accounts
     * @return list<array{account_code: string, account_name: string, account_type: string, amount: numeric-string, level: int, is_parent: bool}>
     */
    private function buildHierarchicalReport(\Illuminate\Database\Eloquent\Collection $accounts, bool $includeZeroBalances): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        // Set calculated balances on accounts for tree building
        $this->setAccountBalances($accounts);

        // Build hierarchy tree
        $tree = $this->hierarchyService->buildTree($accounts);

        // Calculate subtotals for parent accounts
        $this->hierarchyService->calculateSubtotals($tree);

        // Filter zero balances if requested
        if (! $includeZeroBalances) {
            $tree = $this->hierarchyService->filterTree(
                $tree,
                fn ($node) => $this->isNonZero($node->balance),
                keepEmptyParents: true // Keep parents even if they don't match, as long as children do
            );
        }

        // Flatten tree to array format
        $flatTree = $this->hierarchyService->flattenTree($tree);

        // Transform AccountNode objects to P&L line arrays
        return array_map(fn ($node) => $this->formatProfitLossLine($node), $flatTree);
    }

    /**
     * Build flat report (no hierarchy, just sorted by code).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Account>  $accounts  Accounts with calculated balances
     * @param  bool  $includeZeroBalances  Whether to include zero-balance accounts
     * @return list<array{account_code: string, account_name: string, account_type: string, amount: string, level: int, is_parent: bool}>
     */
    private function buildFlatReport(\Illuminate\Database\Eloquent\Collection $accounts, bool $includeZeroBalances): array
    {
        /** @var list<array{account_code: string, account_name: string, account_type: string, amount: string, level: int, is_parent: bool}> */
        return $accounts
            ->filter(function (Account $account) use ($includeZeroBalances) {
                $balance = $account->getAttribute('calculated_balance') ?? '0.0000';

                return $includeZeroBalances || $this->isNonZero($balance);
            })
            ->map(function (Account $account) {
                $balance = $account->getAttribute('calculated_balance') ?? '0.0000';

                return [
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'account_type' => $account->type->value,
                    'amount' => $balance,
                    'level' => 0,
                    'is_parent' => false,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Calculate total from report lines.
     *
     * Sums only the leaf accounts (not parent subtotals) to avoid double-counting.
     *
     * @param  list<array{amount: string, is_parent?: bool}>  $lines  Report lines
     * @return numeric-string Total amount
     */
    private function calculateTotal(array $lines): string
    {
        /** @var numeric-string $total */
        $total = '0.0000';

        foreach ($lines as $line) {
            // Only sum leaf accounts (non-parents) to avoid double-counting
            if (! ($line['is_parent'] ?? false)) {
                /** @var numeric-string $lineAmount */
                $lineAmount = $line['amount'];
                $total = bcadd($total, $lineAmount, self::DECIMAL_SCALE);
            }
        }

        return $total;
    }

    /**
     * Check if a balance is non-zero (above threshold).
     *
     * @param  numeric-string  $balance  Balance to check
     * @return bool True if balance is above zero threshold
     */
    private function isNonZero(string $balance): bool
    {
        /** @var numeric-string $absBalance */
        $absBalance = str_replace('-', '', $balance);

        return bccomp(
            $absBalance,
            self::ZERO_THRESHOLD,
            self::DECIMAL_SCALE
        ) > 0; // Strictly greater than threshold
    }

    /**
     * Set calculated balances on Account models for tree building.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Account>  $accounts  Accounts with calculated_balance attribute
     */
    private function setAccountBalances(\Illuminate\Database\Eloquent\Collection $accounts): void
    {
        foreach ($accounts as $account) {
            $calculatedBalance = $account->getAttribute('calculated_balance') ?? '0.0000';
            // Set as property for AccountHierarchyService
            $account->balance = $calculatedBalance;
        }
    }

    /**
     * Format an AccountNode into a Profit & Loss line array.
     *
     * @return array{account_code: string, account_name: string, account_type: string, amount: numeric-string, level: int, is_parent: bool}
     */
    private function formatProfitLossLine(\App\Modules\Accounting\Domain\Services\AccountNode $node): array
    {
        return [
            'account_code' => $node->account->code,
            'account_name' => $node->account->name,
            'account_type' => $node->account->type->value,
            'amount' => $node->balance,
            'level' => $node->level,
            'is_parent' => $node->isParent,
        ];
    }
}
