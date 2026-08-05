<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\CashReconciliationData;
use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;

final class CashRegisterReportService
{
    use FormatsReportNumbers;

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

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

        // Cash reconciliation is the launch-critical Z/EOD surface: its figures
        // must land on the company's currency scale (TND keeps the millime),
        // never on a hardcoded scale 2.
        //
        // KNOWN LIMITATION — the scale is resolved ONCE from the request's bound
        // company, not per shift row. `ReportsController` calls this with
        // `OwnerReportScope::companyIds(null, $user)` = root + EVERY child, and
        // offers no way to filter a foreign-currency child out, so a TND child
        // under a EUR root has its variance rendered at 2 dp. Rows here ARE
        // per-company (`pos_terminals.company_id` is in hand), so a per-row
        // currency is reachable; tracked in
        // `docs/superpowers/tickets/2026-08-05-l4-mixed-currency-report-scale.md`.
        $scale = $this->scaleResolver->getScaleSafe();

        return array_values($rows->map(fn (object $row): CashReconciliationData => new CashReconciliationData(
            date: (string) $row->date,
            location_id: (string) $row->location_id,
            location_name: (string) $row->location_name,
            terminal_id: (string) $row->terminal_id,
            terminal_name: (string) $row->terminal_name,
            shift_id: (string) $row->shift_id,
            expected_cash: $this->decimalString($row->expected_cash, $scale),
            counted_cash: $this->decimalString($row->counted_cash, $scale),
            variance: $this->decimalString($row->variance, $scale),
            variance_severity: $this->varianceSeverity(
                variance: $this->numericString($row->variance),
                overSoft: $this->numericString($row->over_soft),
                overHard: $this->numericString($row->over_hard),
                underSoft: $this->numericString($row->under_soft),
                underHard: $this->numericString($row->under_hard),
                scale: $scale,
            ),
        ))->all());
    }

    /**
     * Classify a cash variance against the company's soft/hard thresholds.
     *
     * Compared in bcmath, not through a float cast. `$scale + 4` is chosen so the
     * comparison runs strictly finer than the STORAGE scale of BOTH operands —
     * `pos_shifts.variance` is `decimal(16,4)` and the
     * `company_fraud_settings.cash_variance_*` bounds are `decimal(12,4)`, hence
     * the `+ 4`. (It is the operands' storage scale, NOT the quantity domain
     * constant; `QuantityScale` is unrelated here.) At that precision no
     * threshold boundary can be tipped by an IEEE-754 representation error, and
     * equality at a threshold still yields the lower severity, exactly as the
     * previous float comparison did.
     *
     * @param  numeric-string  $variance
     * @param  numeric-string  $overSoft
     * @param  numeric-string  $overHard
     * @param  numeric-string  $underSoft
     * @param  numeric-string  $underHard
     */
    private function varianceSeverity(
        string $variance,
        string $overSoft,
        string $overHard,
        string $underSoft,
        string $underHard,
        int $scale,
    ): string {
        $comparisonScale = $scale + 4;
        $sign = bccomp($variance, '0', $comparisonScale);

        if ($sign === 0) {
            return 'ok';
        }

        /** @var numeric-string $absolute */
        $absolute = $sign < 0 ? bcmul($variance, '-1', $comparisonScale) : $variance;

        $hard = $sign > 0 ? $overHard : $underHard;
        $soft = $sign > 0 ? $overSoft : $underSoft;

        if (bccomp($absolute, $hard, $comparisonScale) > 0) {
            return 'critical';
        }

        if (bccomp($absolute, $soft, $comparisonScale) > 0) {
            return 'warning';
        }

        return 'ok';
    }
}
