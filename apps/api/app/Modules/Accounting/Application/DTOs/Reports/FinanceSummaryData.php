<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * FinanceSummaryData
 *
 * Compact financial snapshot consumed by the Trésorerie FinanceWidget.
 *
 * Every value is a currency-scaled money string (never a float). Values are
 * aggregated from the canonical report services:
 * - total_assets / total_liabilities / total_equity → BalanceSheetService
 * - net_income_mtd / net_income_ytd → ProfitLossService (month-/year-to-date)
 * - accounts_receivable → AgedReceivablesService grand total
 * - accounts_payable → AgedPayablesService grand total
 */
#[TypeScript]
final class FinanceSummaryData extends Data
{
    public function __construct(
        public readonly string $total_assets,
        public readonly string $total_liabilities,
        public readonly string $total_equity,
        public readonly string $net_income_mtd,
        public readonly string $net_income_ytd,
        public readonly string $accounts_receivable,
        public readonly string $accounts_payable,
    ) {}
}
