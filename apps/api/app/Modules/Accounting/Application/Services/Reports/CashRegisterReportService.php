<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\CashReconciliationData;
use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use Illuminate\Support\Facades\DB;

final class CashRegisterReportService
{
    use FormatsReportNumbers;

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return list<CashReconciliationData>
     */
    public function reconciliationSummary(DateRangeData $range, array $companyIds, array $locationIds): array
    {
        if ($companyIds === [] || $locationIds === []) {
            return [];
        }

        $dateExpression = DB::connection()->getDriverName() === 'sqlite'
            ? 'date(pos_shifts.closed_at)'
            : "to_char(date_trunc('day', pos_shifts.closed_at), 'YYYY-MM-DD')";

        $rows = DB::table('pos_shifts')
            ->join('pos_terminals', 'pos_terminals.id', '=', 'pos_shifts.terminal_id')
            ->join('locations', 'locations.id', '=', 'pos_terminals.location_id')
            ->leftJoin('company_fraud_settings', 'company_fraud_settings.company_id', '=', 'pos_terminals.company_id')
            ->whereIn('pos_terminals.company_id', $companyIds)
            ->whereIn('pos_terminals.location_id', $locationIds)
            ->whereNotNull('pos_shifts.closed_at')
            ->whereBetween('pos_shifts.closed_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->selectRaw("{$dateExpression} as date")
            ->selectRaw('locations.id as location_id')
            ->selectRaw('locations.name as location_name')
            ->selectRaw('pos_terminals.id as terminal_id')
            ->selectRaw('pos_terminals.name as terminal_name')
            ->selectRaw('pos_shifts.id as shift_id')
            ->selectRaw('COALESCE(pos_shifts.expected_cash, 0) as expected_cash')
            ->selectRaw('COALESCE(pos_shifts.actual_cash, 0) as counted_cash')
            ->selectRaw('COALESCE(pos_shifts.variance, 0) as variance')
            ->selectRaw('COALESCE(company_fraud_settings.cash_variance_over_soft, 1.0000) as over_soft')
            ->selectRaw('COALESCE(company_fraud_settings.cash_variance_over_hard, 20.0000) as over_hard')
            ->selectRaw('COALESCE(company_fraud_settings.cash_variance_under_soft, 1.0000) as under_soft')
            ->selectRaw('COALESCE(company_fraud_settings.cash_variance_under_hard, 20.0000) as under_hard')
            ->orderBy('pos_shifts.closed_at')
            ->get();

        return array_values($rows->map(fn (object $row): CashReconciliationData => new CashReconciliationData(
            date: (string) $row->date,
            location_id: (string) $row->location_id,
            location_name: (string) $row->location_name,
            terminal_id: (string) $row->terminal_id,
            terminal_name: (string) $row->terminal_name,
            shift_id: (string) $row->shift_id,
            expected_cash: $this->decimalString($row->expected_cash),
            counted_cash: $this->decimalString($row->counted_cash),
            variance: $this->decimalString($row->variance),
            variance_severity: $this->varianceSeverity(
                variance: (float) $row->variance,
                overSoft: (float) $row->over_soft,
                overHard: (float) $row->over_hard,
                underSoft: (float) $row->under_soft,
                underHard: (float) $row->under_hard,
            ),
        ))->all());
    }

    private function varianceSeverity(float $variance, float $overSoft, float $overHard, float $underSoft, float $underHard): string
    {
        $absolute = abs($variance);

        if ($absolute === 0.0) {
            return 'ok';
        }

        $hard = $variance > 0 ? $overHard : $underHard;
        $soft = $variance > 0 ? $overSoft : $underSoft;

        if ($absolute > $hard) {
            return 'critical';
        }

        if ($absolute > $soft) {
            return 'warning';
        }

        return 'ok';
    }
}
