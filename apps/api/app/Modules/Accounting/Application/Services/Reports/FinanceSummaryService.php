<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\FinanceSummaryData;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\Carbon;

/**
 * FinanceSummaryService
 *
 * Assembles the compact snapshot the Trésorerie FinanceWidget renders, reusing
 * the existing canonical report services rather than re-deriving numbers:
 * - balance sheet totals (assets / liabilities / equity) as of today,
 * - profit & loss net income for month-to-date and year-to-date windows,
 * - accounts_receivable / accounts_payable = the GL balances of the accounts
 *   tagged SystemAccountPurpose::CustomerReceivable / SupplierPayable
 *   (411 / 401 families), summed from the SAME balance-sheet line set — so
 *   AR / AP are always numerically consistent with total_assets /
 *   total_liabilities in the same payload. Open documents (invoices, purchase
 *   orders) are deliberately NOT a source: a purchase order is a commitment,
 *   not a payable.
 *
 * Money is currency-scaled once at this boundary (rule 19); intermediates stay
 * at the report scale (4). The service runs inside an authenticated request
 * with a resolved company; the company's own currency drives the scale rather
 * than the ambient CompanyContext, so the output is deterministic regardless
 * of context binding.
 */
final readonly class FinanceSummaryService
{
    /**
     * Intermediate bcmath scale for summing report lines.
     *
     * Matches BalanceSheetService::DECIMAL_SCALE — the line amounts being
     * summed are produced at that scale; the single round to currency scale
     * happens once at the DTO boundary via bcformatStrict.
     */
    private const REPORT_SCALE = 4;

    public function __construct(
        private BalanceSheetService $balanceSheetService,
        private ProfitLossService $profitLossService,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function generate(string $companyId): FinanceSummaryData
    {
        $company = Company::query()->findOrFail($companyId);
        $scale = $this->scaleResolver->getScale((string) $company->currency);

        // Fresh Carbon instance per call: the report services mutate the dates
        // they receive (startOfDay/endOfDay), so copies must not be shared.
        $balanceSheet = $this->balanceSheetService->generate(
            companyId: $companyId,
            asOfDate: Carbon::today(),
            includeZeroBalances: false,
            includeHierarchy: false,
        );

        $mtd = $this->profitLossService->generate(
            companyId: $companyId,
            dateFrom: Carbon::today()->startOfMonth(),
            dateTo: Carbon::today(),
            includeZeroBalances: false,
            includeHierarchy: false,
        );

        $ytd = $this->profitLossService->generate(
            companyId: $companyId,
            dateFrom: Carbon::today()->startOfYear(),
            dateTo: Carbon::today(),
            includeZeroBalances: false,
            includeHierarchy: false,
        );

        // AR from the asset lines (debit-normal), AP from the liability lines
        // (credit-normal) of the same balance sheet — sign conventions and
        // as-of date are therefore identical to the headline totals.
        $accountsReceivable = $this->sumPurposeLines(
            $balanceSheet['assets'],
            $companyId,
            SystemAccountPurpose::CustomerReceivable,
        );
        $accountsPayable = $this->sumPurposeLines(
            $balanceSheet['liabilities'],
            $companyId,
            SystemAccountPurpose::SupplierPayable,
        );

        return new FinanceSummaryData(
            total_assets: CurrencyScale::bcformatStrict((string) $balanceSheet['total_assets'], $scale),
            total_liabilities: CurrencyScale::bcformatStrict((string) $balanceSheet['total_liabilities'], $scale),
            total_equity: CurrencyScale::bcformatStrict((string) $balanceSheet['total_equity'], $scale),
            net_income_mtd: CurrencyScale::bcformatStrict((string) $mtd['net_income'], $scale),
            net_income_ytd: CurrencyScale::bcformatStrict((string) $ytd['net_income'], $scale),
            accounts_receivable: CurrencyScale::bcformatStrict($accountsReceivable, $scale),
            accounts_payable: CurrencyScale::bcformatStrict($accountsPayable, $scale),
        );
    }

    /**
     * Sum the balance-sheet lines belonging to the accounts tagged with the
     * given system purpose.
     *
     * Balance-sheet lines carry account codes (not ids), so the purpose-tagged
     * account codes are resolved first, then matched against leaf lines only
     * (is_parent lines are subtotals — summing them would double-count).
     * Zero-balance accounts are filtered out of the line set upstream, which
     * is equivalent for a sum. Supports multiple accounts per purpose.
     *
     * REQUIRES a flat balance-sheet result (includeHierarchy: false): flat mode
     * reports every account's own direct balance with is_parent=false. Under
     * includeHierarchy: true, parent accounts (e.g. seeded 411/401, which have
     * child accounts) get their amount overwritten with the children subtotal
     * and is_parent=true — the leaf-only skip would then zero AR/AP.
     *
     * @param  list<array{account_code: string, account_name: string, account_type: string, amount: string, level: int, is_parent: bool}>  $lines
     * @return numeric-string
     */
    private function sumPurposeLines(
        array $lines,
        string $companyId,
        SystemAccountPurpose $purpose,
    ): string {
        $codes = Account::query()
            ->where('company_id', $companyId)
            ->withPurpose($purpose)
            ->pluck('code')
            ->all();

        /** @var numeric-string $total */
        $total = '0.0000';

        foreach ($lines as $line) {
            if ($line['is_parent']) {
                continue;
            }

            if (! in_array($line['account_code'], $codes, true)) {
                continue;
            }

            /** @var numeric-string $amount */
            $amount = $line['amount'];
            $total = bcadd($total, $amount, self::REPORT_SCALE);
        }

        return $total;
    }
}
