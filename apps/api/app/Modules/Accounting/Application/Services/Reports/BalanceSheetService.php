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
 * BalanceSheetService
 *
 * Application service for generating Balance Sheet reports (Statement of Financial Position).
 *
 * The Balance Sheet shows a company's financial position at a specific point in time,
 * displaying assets, liabilities, and equity. It verifies the fundamental accounting
 * equation: Assets = Liabilities + Equity.
 *
 * Report Structure:
 * ```
 * ASSETS
 *   Current Assets
 *     Cash                         $10,000
 *     Accounts Receivable          $15,000
 *     Total Current Assets         $25,000
 *
 *   Fixed Assets
 *     Equipment                    $50,000
 *     Total Fixed Assets           $50,000
 *
 *   TOTAL ASSETS                   $75,000
 *
 * LIABILITIES
 *   Current Liabilities
 *     Accounts Payable             $8,000
 *     Total Current Liabilities    $8,000
 *
 *   TOTAL LIABILITIES              $8,000
 *
 * EQUITY
 *   Capital Stock                  $50,000
 *   Retained Earnings              $17,000
 *   TOTAL EQUITY                   $67,000
 *
 * TOTAL LIABILITIES + EQUITY       $75,000
 *
 * Balance Check: ✓ (Assets = Liabilities + Equity)
 * ```
 *
 * Key Features:
 * - Point-in-time snapshot (as_of_date)
 * - Hierarchical account display with subtotals
 * - Asset/Liability/Equity classification
 * - Retained earnings calculation from inception P&L
 * - Balance equation verification
 * - Support for zero-balance filtering
 *
 * Accounting Rules:
 * - Asset accounts: DEBIT balance = asset (debit - credit)
 * - Liability accounts: CREDIT balance = liability (credit - debit)
 * - Equity accounts: CREDIT balance = equity (credit - debit)
 * - Retained Earnings = Cumulative Net Income (inception to as_of_date)
 * - Assets = Liabilities + Equity (fundamental equation)
 *
 * Use Cases:
 * 1. End-of-period financial statements (monthly/quarterly/annual)
 * 2. Bank loan applications (proof of financial position)
 * 3. Investor reporting
 * 4. Management analysis of liquidity and solvency
 * 5. Audit preparation
 *
 * Performance Notes:
 * - Generally fast (balance sheet accounts don't change as frequently as P&L)
 * - Recommended indexes:
 *   - journal_entries(company_id, status, entry_date)
 *   - journal_lines(account_id)
 *   - accounts(company_id, type, is_active)
 * - Consider caching for closed fiscal periods
 * - Retained earnings calculation can be expensive for long-running companies
 *
 * Architectural Notes:
 * - Application layer service (orchestrates domain logic)
 * - Uses AccountHierarchyService for tree building
 * - Uses ProfitLossService for retained earnings calculation
 * - Returns arrays (not DTOs) for flexibility
 * - Controller transforms to DTOs for API response
 */
class BalanceSheetService
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
        private readonly AccountHierarchyService $hierarchyService,
        private readonly ProfitLossService $profitLossService
    ) {}

    /**
     * Generate a Balance Sheet report.
     *
     * Returns asset, liability, and equity accounts with their balances as of
     * the specified date, along with calculated retained earnings and balance verification.
     *
     * Algorithm:
     * 1. Query asset account balances (debit - credit) as of date
     * 2. Query liability account balances (credit - debit) as of date
     * 3. Query equity account balances (credit - debit) as of date
     * 4. Calculate retained earnings from cumulative P&L (inception to as_of_date)
     * 5. Build hierarchical trees for assets, liabilities, and equity
     * 6. Calculate totals
     * 7. Verify accounting equation: assets = liabilities + equity
     * 8. Return formatted array
     *
     * @param  string  $companyId  Company UUID for multi-tenancy
     * @param  Carbon  $asOfDate  Point-in-time date for the balance sheet
     * @param  bool  $includeZeroBalances  Whether to include accounts with zero balance
     * @param  bool  $includeHierarchy  Whether to build hierarchical structure
     * @return array{
     *     assets: list<array{
     *         account_code: string,
     *         account_name: string,
     *         account_type: string,
     *         amount: string,
     *         level: int,
     *         is_parent: bool
     *     }>,
     *     liabilities: list<array{
     *         account_code: string,
     *         account_name: string,
     *         account_type: string,
     *         amount: string,
     *         level: int,
     *         is_parent: bool
     *     }>,
     *     equity: list<array{
     *         account_code: string,
     *         account_name: string,
     *         account_type: string,
     *         amount: string,
     *         level: int,
     *         is_parent: bool
     *     }>,
     *     total_assets: numeric-string,
     *     total_liabilities: numeric-string,
     *     total_equity: numeric-string,
     *     retained_earnings: numeric-string,
     *     is_balanced: bool,
     *     as_of_date: string
     * }
     */
    public function generate(
        string $companyId,
        Carbon $asOfDate,
        bool $includeZeroBalances = false,
        bool $includeHierarchy = true
    ): array {
        // Normalize date to end of day
        $asOfDate = $asOfDate->endOfDay();

        // Step 1: Query asset account balances
        $assetBalances = $this->queryAccountBalances(
            $companyId,
            AccountType::Asset,
            $asOfDate
        );

        // Step 2: Query liability account balances
        $liabilityBalances = $this->queryAccountBalances(
            $companyId,
            AccountType::Liability,
            $asOfDate
        );

        // Step 3: Query equity account balances
        $equityBalances = $this->queryAccountBalances(
            $companyId,
            AccountType::Equity,
            $asOfDate
        );

        // Step 4: Load Account models with calculated balances
        $assetAccounts = $this->loadAccountsWithBalances($assetBalances);
        $liabilityAccounts = $this->loadAccountsWithBalances($liabilityBalances);
        $equityAccounts = $this->loadAccountsWithBalances($equityBalances);

        // Step 5: Calculate retained earnings from cumulative P&L
        $retainedEarnings = $this->calculateRetainedEarnings($companyId, $asOfDate);

        // Step 6: Build hierarchical structure or flat list
        if ($includeHierarchy) {
            $assetLines = $this->buildHierarchicalReport($assetAccounts, $includeZeroBalances);
            $liabilityLines = $this->buildHierarchicalReport($liabilityAccounts, $includeZeroBalances);
            $equityLines = $this->buildHierarchicalReport($equityAccounts, $includeZeroBalances);
        } else {
            $assetLines = $this->buildFlatReport($assetAccounts, $includeZeroBalances);
            $liabilityLines = $this->buildFlatReport($liabilityAccounts, $includeZeroBalances);
            $equityLines = $this->buildFlatReport($equityAccounts, $includeZeroBalances);
        }

        // Step 7: Calculate totals
        $totalAssets = $this->calculateTotal($assetLines);
        $totalLiabilities = $this->calculateTotal($liabilityLines);
        $totalEquity = bcadd(
            $this->calculateTotal($equityLines),
            $retainedEarnings,
            self::DECIMAL_SCALE
        );

        // Step 8: Verify accounting equation: Assets = Liabilities + Equity
        $totalLiabilitiesAndEquity = bcadd($totalLiabilities, $totalEquity, self::DECIMAL_SCALE);
        $isBalanced = bccomp($totalAssets, $totalLiabilitiesAndEquity, self::DECIMAL_SCALE) === 0;

        return [
            'assets' => $assetLines,
            'liabilities' => $liabilityLines,
            'equity' => $equityLines,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'retained_earnings' => $retainedEarnings,
            'is_balanced' => $isBalanced,
            'as_of_date' => $asOfDate->toDateString(),
        ];
    }

    /**
     * Query account balances for a specific account type and as-of date.
     *
     * For asset accounts: amount = debit - credit (normal debit balance)
     * For liability accounts: amount = credit - debit (normal credit balance)
     * For equity accounts: amount = credit - debit (normal credit balance)
     *
     * @param  string  $companyId  Company UUID
     * @param  AccountType  $accountType  Type of accounts to query (asset, liability, equity)
     * @param  Carbon  $asOfDate  As-of date for the balance sheet
     * @return Collection<int, \stdClass>
     */
    private function queryAccountBalances(
        string $companyId,
        AccountType $accountType,
        Carbon $asOfDate
    ): Collection {
        // Build the balance calculation based on account type
        // Assets: debit - credit (normal debit balance)
        // Liabilities/Equity: credit - debit (normal credit balance)
        $balanceFormula = $accountType === AccountType::Asset
            ? 'COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)'
            : 'COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0)';

        return DB::table('accounts as a')
            ->leftJoin('journal_lines as jl', function ($join) use ($asOfDate) {
                $join->on('jl.account_id', '=', 'a.id')
                    ->join('journal_entries as je', function ($entryJoin) use ($asOfDate) {
                        $entryJoin->on('je.id', '=', 'jl.journal_entry_id')
                            ->where('je.status', '=', JournalEntryStatus::Posted->value)
                            ->where('je.entry_date', '<=', $asOfDate->toDateString());
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
                // Attach calculated balance as a dynamic attribute
                $account->setAttribute('calculated_balance', (string) $balanceData->balance);
                $accountsWithBalances[] = $account;
            }
        }

        return new \Illuminate\Database\Eloquent\Collection($accountsWithBalances);
    }

    /**
     * Calculate retained earnings from cumulative profit & loss.
     *
     * Retained earnings = All-time net income from inception to as_of_date.
     *
     * This is the cumulative result of all revenue and expense transactions
     * since the company's inception (or start of fiscal records).
     *
     * @param  string  $companyId  Company UUID
     * @param  Carbon  $asOfDate  As-of date for the calculation
     * @return numeric-string Retained earnings amount
     */
    private function calculateRetainedEarnings(string $companyId, Carbon $asOfDate): string
    {
        // Calculate cumulative P&L from inception to as_of_date
        // Use a very early date as "inception" (e.g., year 2000)
        $inception = Carbon::parse('2000-01-01')->startOfDay();

        $plReport = $this->profitLossService->generate(
            companyId: $companyId,
            dateFrom: $inception,
            dateTo: $asOfDate,
            includeZeroBalances: true, // Include all accounts for accurate calculation
            includeHierarchy: false // Don't need hierarchy for this calculation
        );

        return $plReport['net_income'];
    }

    /**
     * Build hierarchical report with account tree and subtotals.
     *
     * Uses AccountHierarchyService to build the tree structure.
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

        // Transform AccountNode objects to Balance Sheet line arrays
        return array_map(fn ($node) => $this->formatBalanceSheetLine($node), $flatTree);
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
     * AccountHierarchyService reads from $account->balance property,
     * but we store calculated balances in 'calculated_balance' attribute.
     * This method copies calculated_balance to the balance property.
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
     * Format an AccountNode into a Balance Sheet line array.
     *
     * @return array{account_code: string, account_name: string, account_type: string, amount: numeric-string, level: int, is_parent: bool}
     */
    private function formatBalanceSheetLine(\App\Modules\Accounting\Domain\Services\AccountNode $node): array
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
