<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Services\AccountHierarchyService;
use App\Modules\Accounting\Domain\Services\AccountNode;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TrialBalanceService
 *
 * Application service responsible for generating Trial Balance reports.
 *
 * The Trial Balance is a fundamental accounting report that lists all accounts
 * with their debit and credit balances. It's used to verify that total debits
 * equal total credits (fundamental accounting equation).
 *
 * Key Features:
 * - Aggregates journal line data by account
 * - Filters zero-balance accounts (configurable)
 * - Builds hierarchical account structure with subtotals
 * - Validates that debits = credits
 * - Supports "as of date" filtering for point-in-time snapshots
 *
 * Data Integrity:
 * - Uses bcmath for decimal precision (4 decimal places)
 * - Validates double-entry accounting: total_debit MUST equal total_credit
 * - Only includes posted journal entries (draft entries excluded)
 *
 * Performance:
 * - Single SQL query with GROUP BY for efficiency
 * - In-memory tree building (O(n) where n = number of accounts)
 * - Recommended: Add database index on (company_id, status, entry_date)
 *
 * Architectural Notes:
 * - Application Layer service (orchestrates domain services and infrastructure)
 * - Depends on AccountHierarchyService (domain service)
 * - Uses raw SQL queries for performance (not Eloquent)
 * - Returns array data (transformed to DTOs by controllers)
 *
 * Compliance:
 * - Critical for financial audits and regulatory compliance
 * - Hash chain verification should be performed separately
 * - Must match sub-ledger totals (AR/AP)
 */
class TrialBalanceService
{
    /**
     * Decimal scale for INTERNAL financial calculations.
     *
     * This is the working precision the accumulators and comparisons run at —
     * deliberately finer than any currency scale. It is NOT the emission scale:
     * every figure leaving this service is rendered at the company's currency
     * scale by {@see emit()}. (W-8 F-3: the report used to leak three different
     * scales — the raw DB string, this bcmul scale, and a literal `'0.00'` —
     * none of them derived from `companies.currency`.)
     */
    private const DECIMAL_SCALE = 4;

    /**
     * Threshold for treating a balance as zero (0.0001).
     *
     * Balances smaller than this absolute value are considered zero
     * due to floating-point rounding in calculations.
     */
    private const ZERO_THRESHOLD = '0.0001';

    public function __construct(
        private readonly AccountHierarchyService $hierarchyService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * The scale every emitted figure is rendered at — the company currency's,
     * resolved from the bound `CompanyContext` (the trial balance is always
     * generated for the request's own company). `getScaleSafe()` keeps a console
     * invocation from throwing; 3 is the safe maximum fallback.
     */
    private function emissionScale(): int
    {
        return $this->scaleResolver->getScaleSafe();
    }

    /**
     * Render an internal working-precision figure at the currency scale.
     *
     * Half-away-from-zero at the presentation boundary, so the working scale's
     * extra digit is rounded rather than silently dropped.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function emit(string $value, int $scale): string
    {
        return CurrencyScale::bcround($value, $scale);
    }

    /**
     * Generate a Trial Balance report for a company.
     *
     * The trial balance shows all active accounts with their debit/credit balances
     * as of a specific date. It's one of the primary financial reports used to
     * verify the integrity of the general ledger.
     *
     * Process Flow:
     * 1. Query all active accounts with aggregated journal line balances
     * 2. Filter accounts with zero balances (if requested)
     * 3. Build account hierarchy tree
     * 4. Calculate subtotals for parent accounts
     * 5. Flatten tree for display
     * 6. Validate that total debits = total credits
     *
     * @param  string  $companyId  UUID of the company
     * @param  Carbon|null  $asOfDate  Point-in-time date (null = today)
     * @param  bool  $includeZeroBalances  Whether to include accounts with zero balance (default: false)
     * @param  bool  $includeHierarchy  Whether to build hierarchical structure (default: true)
     * @return array{lines: list<array>, total_debit: numeric-string, total_credit: numeric-string, is_balanced: bool, as_of_date: string}
     *
     * @example
     * ```php
     * $report = $service->generate($companyId, Carbon::parse('2025-12-31'));
     * // Returns:
     * // [
     * //   'lines' => [
     * //     ['account_code' => '100', 'account_name' => 'Assets', 'debit' => '10000.00', 'credit' => '0.00', 'level' => 0, 'is_parent' => true],
     * //     ['account_code' => '110', 'account_name' => 'Cash', 'debit' => '5000.00', 'credit' => '0.00', 'level' => 1, 'is_parent' => false],
     * //   ],
     * //   'total_debit' => '10000.00',
     * //   'total_credit' => '10000.00',
     * //   'is_balanced' => true,
     * //   'as_of_date' => '2025-12-31'
     * // ]
     * ```
     */
    public function generate(
        string $companyId,
        ?Carbon $asOfDate = null,
        bool $includeZeroBalances = false,
        bool $includeHierarchy = true
    ): array {
        // Default to today if no date specified
        $asOfDate = $asOfDate ?? Carbon::now()->endOfDay();

        // Step 1: Query account balances from journal lines
        $accountBalances = $this->queryAccountBalances($companyId, $asOfDate);

        // Step 2: Filter zero balances if requested
        if (! $includeZeroBalances) {
            $accountBalances = $this->filterZeroBalances($accountBalances);
        }

        // Step 3: Load full account models for hierarchy building
        $accounts = $this->loadAccounts($companyId, $accountBalances);

        // The scale every figure in this report is emitted at. Resolved ONCE so
        // no two fields in one payload can disagree (W-8 F-3).
        $scale = $this->emissionScale();

        // Step 4: Build hierarchy if requested
        if ($includeHierarchy && $accounts->isNotEmpty()) {
            $lines = $this->buildHierarchicalReport($accounts, $scale);
        } else {
            $lines = $this->buildFlatReport($accounts, $scale);
        }

        // Step 5: Calculate totals (at the internal working precision)
        $totals = $this->calculateTotals($accountBalances);

        // Step 6: Validate accounting equation — on the UNROUNDED totals, so the
        // balance check is never decided by the presentation rounding.
        $isBalanced = $this->validateBalance($totals['total_debit'], $totals['total_credit']);

        return [
            'lines' => $lines,
            'total_debit' => $this->emit($totals['total_debit'], $scale),
            'total_credit' => $this->emit($totals['total_credit'], $scale),
            'is_balanced' => $isBalanced,
            'as_of_date' => $asOfDate->toDateString(),
        ];
    }

    /**
     * Query account balances from journal lines.
     *
     * This query aggregates all journal line data by account, summing
     * debits and credits for posted journal entries up to the as_of_date.
     *
     * SQL Strategy:
     * - LEFT JOIN to include accounts with no journal lines (zero balance)
     * - Filter by posted status only (exclude drafts)
     * - Filter by entry_date <= as_of_date for point-in-time snapshot
     * - GROUP BY account to aggregate balances
     * - COALESCE to handle NULL values (accounts with no transactions)
     *
     * Performance Notes:
     * - This query can be expensive for large datasets
     * - Recommended indexes:
     *   - journal_entries(company_id, status, entry_date)
     *   - journal_lines(journal_entry_id, account_id)
     *   - accounts(company_id, is_active)
     *
     * @param  string  $companyId  UUID of the company
     * @param  Carbon  $asOfDate  Point-in-time cutoff date
     * @return Collection<int, \stdClass>
     */
    private function queryAccountBalances(string $companyId, Carbon $asOfDate): Collection
    {
        // NOTE: The nested $join->join() inside leftJoin() is intentional and correct.
        // Laravel compiles this as a parenthesized join group:
        //   LEFT JOIN ("journal_lines" INNER JOIN "journal_entries" ON ...) ON "jl"."account_id" = "a"."id"
        // The INNER JOIN between journal_lines and journal_entries is evaluated FIRST within
        // the parentheses, producing a derived set of only posted lines. The LEFT JOIN then
        // connects accounts to this derived set, preserving accounts with no matching lines
        // (they get NULLs, handled by COALESCE). This is valid PostgreSQL syntax.
        return DB::table('accounts as a')
            ->leftJoin('journal_lines as jl', function ($join) use ($asOfDate) {
                $join->on('jl.account_id', '=', 'a.id')
                    ->join('journal_entries as je', function ($entryJoin) use ($asOfDate) {
                        $entryJoin->on('je.id', '=', 'jl.journal_entry_id')
                            ->where('je.status', '=', 'posted')
                            ->where('je.entry_date', '<=', $asOfDate);
                    });
            })
            ->where('a.company_id', '=', $companyId)
            ->where('a.is_active', '=', true)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.parent_id')
            ->selectRaw('
                a.id as account_id,
                a.code as account_code,
                a.name as account_name,
                a.type as account_type,
                a.parent_id,
                COALESCE(SUM(jl.debit), 0) as total_debit,
                COALESCE(SUM(jl.credit), 0) as total_credit,
                COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) as balance
            ')
            ->orderBy('a.code')
            ->get();
    }

    /**
     * Filter out accounts with zero balances.
     *
     * @param  Collection<int, \stdClass>  $accountBalances
     * @return Collection<int, \stdClass>
     */
    private function filterZeroBalances(Collection $accountBalances): Collection
    {
        return $accountBalances->filter(function (\stdClass $account): bool {
            /** @var numeric-string $balance */
            $balance = (string) $account->balance;
            /** @var numeric-string $absValue */
            $absValue = bccomp($balance, '0', self::DECIMAL_SCALE) < 0
                ? bcmul($balance, '-1', self::DECIMAL_SCALE)
                : $balance;

            return bccomp($absValue, self::ZERO_THRESHOLD, self::DECIMAL_SCALE) > 0;
        });
    }

    /**
     * Load full Account models for hierarchy building.
     *
     * @param  string  $companyId  UUID of the company
     * @param  Collection<int, \stdClass>  $balances
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    private function loadAccounts(string $companyId, Collection $balances): \Illuminate\Database\Eloquent\Collection
    {
        if ($balances->isEmpty()) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $accountIds = $balances->pluck('account_id')->toArray();

        // Load accounts and attach balance data
        $accounts = Account::query()
            ->whereIn('id', $accountIds)
            ->where('company_id', $companyId)
            ->get();

        // Attach calculated balances to Account models
        foreach ($accounts as $account) {
            $balanceData = $balances->firstWhere('account_id', $account->id);
            if ($balanceData !== null) {
                // Store balance data as custom property (not persisted)
                $account->setAttribute('calculated_balance', (string) $balanceData->balance);
                $account->setAttribute('total_debit', (string) $balanceData->total_debit);
                $account->setAttribute('total_credit', (string) $balanceData->total_credit);
            }
        }

        return $accounts;
    }

    /**
     * Build hierarchical trial balance report with subtotals.
     *
     * Process:
     * 1. Build tree using AccountHierarchyService
     * 2. Set node balances from calculated balances
     * 3. Calculate parent account subtotals
     * 4. Flatten tree for display
     * 5. Format each line for API response
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Account>  $accounts
     * @return list<array{account_code: string, account_name: string, account_type: string, debit: numeric-string, credit: numeric-string, level: int, is_parent: bool}>
     */
    private function buildHierarchicalReport(\Illuminate\Database\Eloquent\Collection $accounts, int $scale): array
    {
        // Build hierarchy tree
        $tree = $this->hierarchyService->buildTree($accounts);

        // Set balances on nodes (accounts already have calculated_balance from query)
        foreach ($tree as $rootNode) {
            $this->setNodeBalances($rootNode);
        }

        // Calculate subtotals for parent accounts
        $this->hierarchyService->calculateSubtotals($tree);

        // Flatten tree for display
        $flatTree = $this->hierarchyService->flattenTree($tree);

        // Format for API response
        return array_map(fn ($node) => $this->formatTrialBalanceLine($node, $scale), $flatTree);
    }

    /**
     * Recursively set balances on tree nodes from Account attributes.
     */
    private function setNodeBalances(AccountNode $node): void
    {
        $node->balance = $node->account->getAttribute('calculated_balance') ?? '0';

        foreach ($node->children as $child) {
            $this->setNodeBalances($child);
        }
    }

    /**
     * Build flat (non-hierarchical) trial balance report.
     *
     * Used when includeHierarchy = false. Returns accounts in code order
     * without parent-child structure.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Account>  $accounts
     * @return list<array<string, mixed>>
     */
    private function buildFlatReport(\Illuminate\Database\Eloquent\Collection $accounts, int $scale): array
    {
        /** @var list<array<string, mixed>> */
        return $accounts->map(function (Account $account) use ($scale) {
            /** @var numeric-string $balance */
            $balance = (string) ($account->getAttribute('calculated_balance') ?? '0');

            // Determine which column to show balance in (debit or credit).
            // BOTH columns are emitted at the currency scale, including the zero
            // side — a bare `'0.00'` next to a scale-3 sibling is the W-8 F-3
            // defect.
            $debit = '0';
            $credit = '0';

            if (bccomp($balance, '0', self::DECIMAL_SCALE) > 0) {
                // Positive balance → debit column
                $debit = $balance;
            } elseif (bccomp($balance, '0', self::DECIMAL_SCALE) < 0) {
                // Negative balance → credit column (show absolute value)
                $credit = bcmul($balance, '-1', self::DECIMAL_SCALE);
            }

            return [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->type->value,
                'debit' => $this->emit($debit, $scale),
                'credit' => $this->emit($credit, $scale),
                'level' => 0,
                'is_parent' => false,
            ];
        })->values()->toArray();
    }

    /**
     * Format an AccountNode into trial balance line array.
     *
     * Converts node balance into debit/credit presentation:
     * - Positive balance → debit column
     * - Negative balance → credit column (absolute value)
     * - Zero balance → both columns zero
     *
     * @return array{account_code: string, account_name: string, account_type: string, debit: numeric-string, credit: numeric-string, level: int, is_parent: bool}
     */
    private function formatTrialBalanceLine(AccountNode $node, int $scale): array
    {
        $balance = $node->balance;

        // Determine debit/credit display
        $debit = '0';
        $credit = '0';

        $comparison = bccomp($balance, '0', self::DECIMAL_SCALE);

        if ($comparison > 0) {
            // Positive balance → debit
            $debit = $balance;
        } elseif ($comparison < 0) {
            // Negative balance → credit (absolute value)
            $credit = bcmul($balance, '-1', self::DECIMAL_SCALE);
        }

        return [
            'account_code' => $node->account->code,
            'account_name' => $node->account->name,
            'account_type' => $node->account->type->value,
            'debit' => $this->emit($debit, $scale),
            'credit' => $this->emit($credit, $scale),
            'level' => $node->level,
            'is_parent' => $node->isParent,
        ];
    }

    /**
     * Calculate total debits and credits across all accounts.
     *
     * @param  Collection<int, \stdClass>  $accountBalances
     * @return array{total_debit: numeric-string, total_credit: numeric-string}
     */
    private function calculateTotals(Collection $accountBalances): array
    {
        $totalDebit = bcadd('0', '0', self::DECIMAL_SCALE);
        $totalCredit = bcadd('0', '0', self::DECIMAL_SCALE);

        foreach ($accountBalances as $account) {
            /** @var numeric-string $acctDebit */
            $acctDebit = (string) $account->total_debit;
            /** @var numeric-string $acctCredit */
            $acctCredit = (string) $account->total_credit;
            $totalDebit = bcadd($totalDebit, $acctDebit, self::DECIMAL_SCALE);
            $totalCredit = bcadd($totalCredit, $acctCredit, self::DECIMAL_SCALE);
        }

        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ];
    }

    /**
     * Validate that total debits equal total credits.
     *
     * This is a fundamental accounting principle (double-entry bookkeeping).
     * If debits != credits, there's a data integrity issue.
     *
     * Uses ZERO_THRESHOLD to account for floating-point precision issues.
     *
     * @param  numeric-string  $totalDebit
     * @param  numeric-string  $totalCredit
     * @return bool True if balanced (debits = credits within threshold)
     */
    private function validateBalance(string $totalDebit, string $totalCredit): bool
    {
        $difference = bcsub($totalDebit, $totalCredit, self::DECIMAL_SCALE);
        $absDifference = bcabs($difference, self::DECIMAL_SCALE);

        // Compare absolute difference to threshold
        $comparison = bccomp($absDifference, self::ZERO_THRESHOLD, self::DECIMAL_SCALE);

        return $comparison <= 0; // True if abs(difference) <= threshold
    }
}

/**
 * Helper function to get absolute value using bcmath.
 *
 * @param  numeric-string  $value
 * @param  int  $scale  Decimal scale
 * @return numeric-string
 */
function bcabs(string $value, int $scale): string
{
    return bccomp($value, '0', $scale) < 0
        ? bcmul($value, '-1', $scale)
        : $value;
}
