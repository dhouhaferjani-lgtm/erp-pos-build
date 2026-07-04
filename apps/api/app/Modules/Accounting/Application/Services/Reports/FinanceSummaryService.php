<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\FinanceSummaryData;
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
 * - aged-receivables / aged-payables grand totals (outstanding balances).
 *
 * Money is currency-scaled once at this boundary (rule 19). The service runs
 * inside an authenticated request with a resolved company; the company's own
 * currency drives the scale rather than the ambient CompanyContext, so the
 * output is deterministic regardless of context binding.
 */
final readonly class FinanceSummaryService
{
    public function __construct(
        private BalanceSheetService $balanceSheetService,
        private ProfitLossService $profitLossService,
        private AgedReceivablesService $agedReceivablesService,
        private AgedPayablesService $agedPayablesService,
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

        $receivables = $this->agedReceivablesService->generate($companyId, Carbon::today());
        $payables = $this->agedPayablesService->generate($companyId, Carbon::today());

        return new FinanceSummaryData(
            total_assets: CurrencyScale::bcformatStrict((string) $balanceSheet['total_assets'], $scale),
            total_liabilities: CurrencyScale::bcformatStrict((string) $balanceSheet['total_liabilities'], $scale),
            total_equity: CurrencyScale::bcformatStrict((string) $balanceSheet['total_equity'], $scale),
            net_income_mtd: CurrencyScale::bcformatStrict((string) $mtd['net_income'], $scale),
            net_income_ytd: CurrencyScale::bcformatStrict((string) $ytd['net_income'], $scale),
            accounts_receivable: CurrencyScale::bcformatStrict($receivables->grand_total, $scale),
            accounts_payable: CurrencyScale::bcformatStrict($payables->grand_total, $scale),
        );
    }
}
