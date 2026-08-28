<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\CategoryRevenueData;
use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\DTOs\Reports\PaymentMethodBreakdownData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesByLocationData;
use App\Modules\Accounting\Application\DTOs\Reports\TopSkuData;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;

final class SalesReportService
{
    use FormatsReportNumbers;

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Currency scale for every money figure this report emits.
     *
     * KNOWN LIMITATION — resolved ONCE, from the request's bound company
     * (`CompanyContext`), not per result row. The owner report scope is the root
     * company plus EVERY child (`OwnerReportScope::companyIds`), so a
     * mixed-currency parent/child group renders the children's figures at the
     * ROOT's scale — a TND child under a EUR root loses its millime. The
     * underlying cross-currency `SUM` is already meaningless before any
     * formatting (pre-existing), which is why this is a reporting-contract debt
     * rather than a money bug.
     *
     * The sibling `OwnerSalesSummaryService::resolveCurrency()` takes the
     * stronger line — it derives the scale from the DATA and REFUSES a
     * mixed-currency scope — so this service family currently carries two
     * different answers to the same question. Unifying them is tracked in
     * `docs/superpowers/tickets/2026-08-05-l4-mixed-currency-report-scale.md`;
     * `salesByLocation` is per-company-row and already joins `companies`, so it
     * can carry a per-row currency once the ruling lands.
     */
    private function moneyScale(): int
    {
        return $this->scaleResolver->getScaleSafe();
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return list<SalesByLocationData>
     */
    public function salesByLocation(DateRangeData $range, array $companyIds, array $locationIds, string $granularity): array
    {
        if ($companyIds === [] || $locationIds === []) {
            return [];
        }

        $periodExpression = $this->periodExpression($granularity, 'pos_receipts.posted_at');

        $rows = DB::table('pos_receipts')
            ->join('companies', 'companies.id', '=', 'pos_receipts.company_id')
            ->join('locations', 'locations.id', '=', 'pos_receipts.location_id')
            ->whereIn('pos_receipts.company_id', $companyIds)
            ->whereIn('pos_receipts.location_id', $locationIds)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.training_flag', false)
            // Breakdowns report SALES only — i.e. GROSS; returns are a separate metric
            // (OwnerSalesSummaryService). (F-5, AMENDED 2026-08-21 by O-28.)
            //
            // The original F-5 rationale — "keeps the drill-downs consistent with the
            // headline KPIs" — is now FALSE: the owner ruled the Today's-Sales headline
            // is NET, EXCLUDING REFUNDS, so headline and breakdowns differ BY SCOPE
            // DECISION, not by accident. What still justifies gross here is that a
            // refund carries no location/SKU/category attribution safe to net against an
            // arbitrary grouping key, and that gross is sign-era-proof without ABS
            // handling. Because the two now disagree, ALL SIX surfaces rendering this
            // data are LABELLED gross: branchLeaderboard, salesTrend, salesByLocation,
            // topSkus (title + revenue column), revenueByCategory, and paymentMethods
            // (title + amount column) — en/fr/ar. Revisiting is open question O-28-a in
            // docs/superpowers/tickets/2026-08-21-o28-todays-sales-audit.md.
            ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
            ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->groupByRaw($periodExpression)
            ->groupBy('companies.id', 'companies.name', 'locations.id', 'locations.name')
            ->selectRaw("{$periodExpression} as period")
            ->selectRaw('companies.id as company_id')
            ->selectRaw('companies.name as company_name')
            ->selectRaw('locations.id as location_id')
            ->selectRaw('locations.name as location_name')
            ->selectRaw('COALESCE(SUM(pos_receipts.total), 0) as gross_sales')
            ->selectRaw('COUNT(*) as receipt_count')
            ->orderBy('period')
            ->orderBy('locations.name')
            ->get();

        $scale = $this->moneyScale();

        return array_values($rows->map(fn (object $row): SalesByLocationData => new SalesByLocationData(
            period: (string) $row->period,
            company_id: (string) $row->company_id,
            company_name: (string) $row->company_name,
            location_id: (string) $row->location_id,
            location_name: (string) $row->location_name,
            gross_sales: $this->decimalString($row->gross_sales, $scale),
            receipt_count: (int) $row->receipt_count,
        ))->all());
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return list<TopSkuData>
     */
    public function topSkus(DateRangeData $range, array $companyIds, array $locationIds, int $limit, string $sortBy): array
    {
        if ($companyIds === [] || $locationIds === []) {
            return [];
        }

        $query = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->leftJoin('products', 'products.id', '=', 'pos_receipt_lines.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->whereIn('pos_receipts.company_id', $companyIds)
            ->whereIn('pos_receipts.location_id', $locationIds)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.training_flag', false)
            // Breakdowns report SALES only — i.e. GROSS; returns are a separate metric
            // (OwnerSalesSummaryService). (F-5, AMENDED 2026-08-21 by O-28.)
            //
            // The original F-5 rationale — "keeps the drill-downs consistent with the
            // headline KPIs" — is now FALSE: the owner ruled the Today's-Sales headline
            // is NET, EXCLUDING REFUNDS, so headline and breakdowns differ BY SCOPE
            // DECISION, not by accident. What still justifies gross here is that a
            // refund carries no location/SKU/category attribution safe to net against an
            // arbitrary grouping key, and that gross is sign-era-proof without ABS
            // handling. Because the two now disagree, ALL SIX surfaces rendering this
            // data are LABELLED gross: branchLeaderboard, salesTrend, salesByLocation,
            // topSkus (title + revenue column), revenueByCategory, and paymentMethods
            // (title + amount column) — en/fr/ar. Revisiting is open question O-28-a in
            // docs/superpowers/tickets/2026-08-21-o28-todays-sales-audit.md.
            ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
            ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->groupBy('pos_receipt_lines.product_id', 'pos_receipt_lines.product_name', 'products.sku', 'units.decimal_places')
            ->selectRaw('pos_receipt_lines.product_id')
            ->selectRaw('pos_receipt_lines.product_name')
            ->selectRaw('products.sku')
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.line_total), 0) as revenue')
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.quantity), 0) as quantity')
            ->selectRaw('COALESCE(units.decimal_places, 4) as quantity_decimals')
            ->limit($limit);

        $sortBy === 'quantity'
            ? $query->orderByDesc('quantity')
            : $query->orderByDesc('revenue');

        $scale = $this->moneyScale();

        return array_values($query->get()->map(fn (object $row): TopSkuData => new TopSkuData(
            product_id: $row->product_id === null ? null : (string) $row->product_id,
            product_name: (string) $row->product_name,
            sku: $row->sku === null ? null : (string) $row->sku,
            revenue: $this->decimalString($row->revenue, $scale),
            // A quantity is unit-scaled, never currency-scaled: the row already
            // carries the unit's `decimal_places`, which is also what the
            // client renders with (`quantity_decimals`).
            quantity: $this->quantityString($row->quantity, (int) $row->quantity_decimals),
            quantity_decimals: (int) $row->quantity_decimals,
        ))->all());
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return list<CategoryRevenueData>
     */
    public function revenueByCategory(DateRangeData $range, array $companyIds, array $locationIds): array
    {
        if ($companyIds === [] || $locationIds === []) {
            return [];
        }

        $rows = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->leftJoin('products', 'products.id', '=', 'pos_receipt_lines.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('pos_receipts.company_id', $companyIds)
            ->whereIn('pos_receipts.location_id', $locationIds)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.training_flag', false)
            // Breakdowns report SALES only — i.e. GROSS; returns are a separate metric
            // (OwnerSalesSummaryService). (F-5, AMENDED 2026-08-21 by O-28.)
            //
            // The original F-5 rationale — "keeps the drill-downs consistent with the
            // headline KPIs" — is now FALSE: the owner ruled the Today's-Sales headline
            // is NET, EXCLUDING REFUNDS, so headline and breakdowns differ BY SCOPE
            // DECISION, not by accident. What still justifies gross here is that a
            // refund carries no location/SKU/category attribution safe to net against an
            // arbitrary grouping key, and that gross is sign-era-proof without ABS
            // handling. Because the two now disagree, ALL SIX surfaces rendering this
            // data are LABELLED gross: branchLeaderboard, salesTrend, salesByLocation,
            // topSkus (title + revenue column), revenueByCategory, and paymentMethods
            // (title + amount column) — en/fr/ar. Revisiting is open question O-28-a in
            // docs/superpowers/tickets/2026-08-21-o28-todays-sales-audit.md.
            ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
            ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id as category_id')
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as category_name")
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.line_total), 0) as revenue')
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.quantity), 0) as quantity')
            ->orderByDesc('revenue')
            ->get();

        $scale = $this->moneyScale();

        // Money never goes through a float: the share denominator is summed in
        // bcmath at a derived working scale, and each share is divided at the
        // same working precision before being rounded once, on emission.
        $workingScale = $scale + 6;
        $totalRevenue = $rows->reduce(
            fn (string $carry, object $row): string => bcadd($carry, $this->numericString($row->revenue), $workingScale),
            '0',
        );
        $hasRevenue = bccomp($totalRevenue, '0', $workingScale) > 0;

        return array_values($rows->map(fn (object $row): CategoryRevenueData => new CategoryRevenueData(
            category_id: $row->category_id === null ? null : (int) $row->category_id,
            category_name: (string) $row->category_name,
            revenue: $this->decimalString($row->revenue, $scale),
            percentage: $this->percentString(
                $hasRevenue
                    ? bcmul(bcdiv($this->numericString($row->revenue), $totalRevenue, $workingScale), '100', $workingScale)
                    : '0',
            ),
            // Categories aggregate across products that may not share a unit, so
            // there is no single `decimal_places` to render at — fall back to the
            // canonical quantity storage scale rather than the currency scale.
            quantity: $this->quantityString($row->quantity, null),
        ))->all());
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return list<PaymentMethodBreakdownData>
     */
    public function paymentMethodBreakdown(DateRangeData $range, array $companyIds, array $locationIds): array
    {
        if ($companyIds === [] || $locationIds === []) {
            return [];
        }

        $rows = DB::table('pos_receipt_payments')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_payments.receipt_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'pos_receipt_payments.payment_method_id')
            ->whereIn('pos_receipts.company_id', $companyIds)
            ->whereIn('pos_receipts.location_id', $locationIds)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.training_flag', false)
            // Breakdowns report SALES only — i.e. GROSS; returns are a separate metric
            // (OwnerSalesSummaryService). (F-5, AMENDED 2026-08-21 by O-28.)
            //
            // The original F-5 rationale — "keeps the drill-downs consistent with the
            // headline KPIs" — is now FALSE: the owner ruled the Today's-Sales headline
            // is NET, EXCLUDING REFUNDS, so headline and breakdowns differ BY SCOPE
            // DECISION, not by accident. What still justifies gross here is that a
            // refund carries no location/SKU/category attribution safe to net against an
            // arbitrary grouping key, and that gross is sign-era-proof without ABS
            // handling. Because the two now disagree, ALL SIX surfaces rendering this
            // data are LABELLED gross: branchLeaderboard, salesTrend, salesByLocation,
            // topSkus (title + revenue column), revenueByCategory, and paymentMethods
            // (title + amount column) — en/fr/ar. Revisiting is open question O-28-a in
            // docs/superpowers/tickets/2026-08-21-o28-todays-sales-audit.md.
            ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
            ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->groupBy('pos_receipt_payments.payment_type', 'payment_methods.name')
            ->selectRaw('pos_receipt_payments.payment_type')
            // Raw (un-coalesced) group key column, kept alongside the display
            // name below, so the change-netting lookup can be joined on the
            // BYTE-IDENTICAL (payment_type, payment_methods.name) pair this
            // query groups on. See the netting comment below for why this
            // must not drift from the GROUP BY.
            ->selectRaw('payment_methods.name as raw_method_name')
            ->selectRaw('COALESCE(payment_methods.name, pos_receipt_payments.payment_type) as payment_method_name')
            ->selectRaw('COALESCE(SUM(pos_receipt_payments.amount), 0) as amount')
            // COUNT DISTINCT receipts, not payment rows — a split-tender receipt
            // (e.g. two cash legs) is ONE transaction, not two. (Lane A defect 2,
            // first-tenant launch audit.)
            ->selectRaw('COUNT(DISTINCT pos_receipts.id) as transaction_count')
            ->get();

        // `pos_receipt_payments.amount` on a cash leg is the TENDERED amount;
        // `pos_receipts.change_due` is the cash handed back and must be netted
        // out of the cash group exactly ONCE per receipt — never once per cash
        // payment row (a receipt can carry several cash legs in a split
        // payment) and never leaked into a non-cash group OR a same-code cash
        // group belonging to a DIFFERENT payment method. We pre-aggregate
        // per-receipt change_due in a subquery BEFORE summing. The deprecated
        // `ReportGenerationService::buildExpectedPerMethod()` compatibility
        // query faces the identical row-fan-out hazard: MAX(change_due) grouped
        // by receipt first, summed second. Subtracting `pos_receipts.change_due`
        // directly in the outer SUM after the payment-row join would multiply
        // the change by the number of cash rows on the receipt (double- or
        // triple-counting it for split cash tenders) — pre-aggregating per
        // receipt avoids that fan-out entirely. (Lane A defect 1, first-tenant
        // launch audit.)
        //
        // Cash classification follows the linked payment method's canonical
        // flag. The join is already required for the report group name, so the
        // classification adds no query and cannot drift from lane I-1.
        //
        // CRITICAL (treasury-reviewer round 1): the outer query above groups
        // on the PAIR (payment_type, payment_methods.name) — `payment_type`
        // alone is the payment-method CODE (e.g. "CASH", see
        // PosCoreReceiptProjection::resolvePaymentTypeDisplayName), which is
        // NOT unique across companies. The default owner report scope is the
        // root company plus every child (OwnerReportScope::companyIds), and
        // sibling companies can each hold their own CASH-coded method under a
        // DIFFERENT display name ("Cash" vs "Espèces") — two distinct outer
        // groups sharing `payment_type = 'CASH'`. Keying the change lookup on
        // `payment_type` alone would collapse both companies' change into one
        // bucket and subtract the FULL combined total from EVERY row sharing
        // that code. This subquery therefore joins `payment_methods` and
        // groups on the SAME (payment_type, payment_methods.name) pair as the
        // outer query, so the lookup below can never cross a group boundary
        // the outer query itself draws.
        $cashChangeRows = DB::query()
            ->fromSub(
                DB::table('pos_receipt_payments')
                    ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_payments.receipt_id')
                    ->leftJoin('payment_methods', 'payment_methods.id', '=', 'pos_receipt_payments.payment_method_id')
                    ->whereIn('pos_receipts.company_id', $companyIds)
                    ->whereIn('pos_receipts.location_id', $locationIds)
                    ->where('pos_receipts.is_voided', false)
                    ->where('pos_receipts.training_flag', false)
                    ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
                    ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
                    ->where('payment_methods.is_cash_tender', true)
                    ->selectRaw('pos_receipt_payments.payment_type as payment_type, payment_methods.name as raw_method_name, pos_receipts.id as receipt_id, MAX(COALESCE(pos_receipts.change_due, 0)) as change_due')
                    ->groupBy('pos_receipt_payments.payment_type', 'payment_methods.name', 'pos_receipts.id'),
                'cash_receipt_changes',
            )
            ->selectRaw('payment_type, raw_method_name, SUM(change_due) as total_change_due')
            ->groupBy('payment_type', 'raw_method_name')
            ->get();

        /** @var array<string, string> $cashChangeByGroup composite (payment_type, raw_method_name) key -> numeric-string total change_due */
        $cashChangeByGroup = [];
        foreach ($cashChangeRows as $changeRow) {
            $rawName = $changeRow->raw_method_name;
            $key = $this->paymentGroupKey((string) $changeRow->payment_type, $rawName === null ? null : (string) $rawName);
            $cashChangeByGroup[$key] = (string) $changeRow->total_change_due;
        }

        $rows = $rows->map(function (object $row) use ($cashChangeByGroup): object {
            $rawName = $row->raw_method_name;
            $key = $this->paymentGroupKey((string) $row->payment_type, $rawName === null ? null : (string) $rawName);

            if (array_key_exists($key, $cashChangeByGroup)) {
                $row->amount = bcsub($this->normaliseNumericString($row->amount), $this->normaliseNumericString($cashChangeByGroup[$key]), 3); // precision-ok: pos_receipt_payments.amount and pos_receipts.change_due are both decimal(12,3) at rest
            }

            return $row;
        });

        $scale = $this->moneyScale();
        $workingScale = $scale + 6;

        // (Minor, treasury-reviewer round 1) Re-sort AFTER netting: the SQL
        // query no longer orders by amount (it can't — netting happens in
        // PHP), and array order is part of the response contract, so the
        // descending sort must run on the NET amount, not the gross sum.
        // Compared in bcmath, not through a float cast (rule 19).
        $rows = $rows->sort(
            fn (object $a, object $b): int => bccomp(
                $this->numericString($b->amount),
                $this->numericString($a->amount),
                $workingScale,
            ),
        )->values();

        $total = $rows->reduce(
            fn (string $carry, object $row): string => bcadd($carry, $this->numericString($row->amount), $workingScale),
            '0',
        );
        $hasTotal = bccomp($total, '0', $workingScale) > 0;

        return array_values($rows->map(fn (object $row): PaymentMethodBreakdownData => new PaymentMethodBreakdownData(
            payment_type: (string) $row->payment_type,
            payment_method_name: (string) $row->payment_method_name,
            amount: $this->decimalString($row->amount, $scale),
            percentage: $this->percentString(
                $hasTotal
                    ? bcmul(bcdiv($this->numericString($row->amount), $total, $workingScale), '100', $workingScale)
                    : '0',
            ),
            transaction_count: (int) $row->transaction_count,
        ))->all());
    }

    /**
     * @return numeric-string
     */
    private function normaliseNumericString(string|int|float|null $value): string
    {
        if (! is_numeric($value)) {
            return '0';
        }

        return (string) $value;
    }

    /**
     * Composite key mirroring the outer query's `GROUP BY (payment_type,
     * payment_methods.name)`. Must stay byte-identical to that GROUP BY so
     * the cash change-netting lookup can never cross a report-group boundary
     * (see the CRITICAL comment above `$cashChangeRows`). Uses a NUL
     * separator plus a null-name sentinel so no combination of real
     * payment_type/name values can collide with a different pair.
     */
    private function paymentGroupKey(string $paymentType, ?string $rawMethodName): string
    {
        return $paymentType."\0".($rawMethodName ?? "\0__NULL__\0");
    }

    private function periodExpression(string $granularity, string $column): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return match ($granularity) {
                // Space separator + HH:00 so the FE hour rollup regex ((?:T|\s|^)(\d{2}):) can extract the hour.
                'hour' => "strftime('%Y-%m-%d %H:00', {$column})",
                'week' => "strftime('%Y-W%W', {$column})",
                'month' => "strftime('%Y-%m', {$column})",
                default => "date({$column})",
            };
        }

        return match ($granularity) {
            // Space separator + HH24:00 — must stay byte-identical to the sqlite branch output.
            'hour' => "to_char(date_trunc('hour', {$column}), 'YYYY-MM-DD HH24:00')",
            'week' => "to_char(date_trunc('week', {$column}), 'YYYY-\"W\"IW')",
            'month' => "to_char(date_trunc('month', {$column}), 'YYYY-MM')",
            default => "to_char(date_trunc('day', {$column}), 'YYYY-MM-DD')",
        };
    }
}
