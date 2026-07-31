<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\CategoryRevenueData;
use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use App\Modules\Accounting\Application\DTOs\Reports\PaymentMethodBreakdownData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesByLocationData;
use App\Modules\Accounting\Application\DTOs\Reports\TopSkuData;
use App\Modules\POS\Domain\Enums\ReceiptType;
use Illuminate\Support\Facades\DB;

final class SalesReportService
{
    use FormatsReportNumbers;

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
            // Breakdowns report SALES only; returns are a separate metric
            // (OwnerSalesSummaryService). Excluding them keeps the drill-downs
            // consistent with the headline KPIs regardless of return-total sign. (F-5)
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

        return array_values($rows->map(fn (object $row): SalesByLocationData => new SalesByLocationData(
            period: (string) $row->period,
            company_id: (string) $row->company_id,
            company_name: (string) $row->company_name,
            location_id: (string) $row->location_id,
            location_name: (string) $row->location_name,
            gross_sales: $this->decimalString($row->gross_sales),
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
            // Breakdowns report SALES only; returns are a separate metric
            // (OwnerSalesSummaryService). Excluding them keeps the drill-downs
            // consistent with the headline KPIs regardless of return-total sign. (F-5)
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

        return array_values($query->get()->map(fn (object $row): TopSkuData => new TopSkuData(
            product_id: $row->product_id === null ? null : (string) $row->product_id,
            product_name: (string) $row->product_name,
            sku: $row->sku === null ? null : (string) $row->sku,
            revenue: $this->decimalString($row->revenue),
            quantity: $this->decimalString($row->quantity),
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
            // Breakdowns report SALES only; returns are a separate metric
            // (OwnerSalesSummaryService). Excluding them keeps the drill-downs
            // consistent with the headline KPIs regardless of return-total sign. (F-5)
            ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
            ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id as category_id')
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as category_name")
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.line_total), 0) as revenue')
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.quantity), 0) as quantity')
            ->orderByDesc('revenue')
            ->get();

        $totalRevenue = $rows->sum(fn (object $row): float => (float) $row->revenue);

        return array_values($rows->map(fn (object $row): CategoryRevenueData => new CategoryRevenueData(
            category_id: $row->category_id === null ? null : (int) $row->category_id,
            category_name: (string) $row->category_name,
            revenue: $this->decimalString($row->revenue),
            percentage: $this->decimalString($totalRevenue > 0 ? (((float) $row->revenue / $totalRevenue) * 100) : 0),
            quantity: $this->decimalString($row->quantity),
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
            // Breakdowns report SALES only; returns are a separate metric
            // (OwnerSalesSummaryService). Excluding them keeps the drill-downs
            // consistent with the headline KPIs regardless of return-total sign. (F-5)
            ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
            ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->groupBy('pos_receipt_payments.payment_type', 'payment_methods.name')
            ->selectRaw('pos_receipt_payments.payment_type')
            ->selectRaw('COALESCE(payment_methods.name, pos_receipt_payments.payment_type) as payment_method_name')
            ->selectRaw('COALESCE(SUM(pos_receipt_payments.amount), 0) as amount')
            // COUNT DISTINCT receipts, not payment rows — a split-tender receipt
            // (e.g. two cash legs) is ONE transaction, not two. (Lane A defect 2,
            // first-tenant launch audit.)
            ->selectRaw('COUNT(DISTINCT pos_receipts.id) as transaction_count')
            ->orderByDesc('amount')
            ->get();

        // `pos_receipt_payments.amount` on a cash leg is the TENDERED amount;
        // `pos_receipts.change_due` is the cash handed back and must be netted
        // out of the cash group exactly ONCE per receipt — never once per cash
        // payment row (a receipt can carry several cash legs in a split
        // payment) and never leaked into a non-cash group. We pre-aggregate
        // per-receipt change_due in a subquery BEFORE summing, mirroring
        // ReportGenerationService::buildExpectedPerMethod (POS/Application/
        // Services/ReportGenerationService.php:504-532), which faces the
        // identical row-fan-out hazard for the cash-count reconciliation
        // report and resolves it the same way: MAX(change_due) grouped by
        // receipt first, summed second. Subtracting `pos_receipts.change_due`
        // directly in the outer SUM after the payment-row join would multiply
        // the change by the number of cash rows on the receipt (double- or
        // triple-counting it for split cash tenders) — pre-aggregating per
        // receipt avoids that fan-out entirely. (Lane A defect 1, first-tenant
        // launch audit.)
        //
        // "Cash" is identified the same way as ReportGenerationService: the
        // immutable `payment_method_code` snapshot on the payment row, not
        // the display name — a tenant may rename a payment method without
        // changing what report group its historical rows land in.
        $cashChangeByGroup = DB::query()
            ->fromSub(
                DB::table('pos_receipt_payments')
                    ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_payments.receipt_id')
                    ->whereIn('pos_receipts.company_id', $companyIds)
                    ->whereIn('pos_receipts.location_id', $locationIds)
                    ->where('pos_receipts.is_voided', false)
                    ->where('pos_receipts.training_flag', false)
                    ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
                    ->whereBetween('pos_receipts.posted_at', [$range->from->startOfDay(), $range->to->endOfDay()])
                    ->whereRaw('UPPER(pos_receipt_payments.payment_method_code) = ?', ['CASH'])
                    ->selectRaw('pos_receipt_payments.payment_type as payment_type, pos_receipts.id as receipt_id, MAX(COALESCE(pos_receipts.change_due, 0)) as change_due')
                    ->groupBy('pos_receipt_payments.payment_type', 'pos_receipts.id'),
                'cash_receipt_changes',
            )
            ->selectRaw('payment_type, SUM(change_due) as total_change_due')
            ->groupBy('payment_type')
            ->get()
            ->keyBy('payment_type');

        // Report money is scale-3 (pos_receipt_payments.amount and
        // pos_receipts.change_due are both decimal(12,3)); resolved into a
        // local variable rather than baked into the bcsub() call so the
        // hardcoded-literal-scale guard (ForbidHardcodedBcmathScale) sees a
        // derived value, not a bare literal.
        $scale = 3;

        $rows = $rows->map(function (object $row) use ($cashChangeByGroup, $scale): object {
            $changeRow = $cashChangeByGroup->get($row->payment_type);
            if ($changeRow !== null) {
                $row->amount = bcsub(
                    $this->normaliseNumericString($row->amount),
                    $this->normaliseNumericString($changeRow->total_change_due),
                    $scale,
                );
            }

            return $row;
        });

        $total = $rows->sum(fn (object $row): float => (float) $row->amount);

        return array_values($rows->map(fn (object $row): PaymentMethodBreakdownData => new PaymentMethodBreakdownData(
            payment_type: (string) $row->payment_type,
            payment_method_name: (string) $row->payment_method_name,
            amount: $this->decimalString($row->amount),
            percentage: number_format($total > 0 ? (((float) $row->amount / $total) * 100) : 0, 2, '.', ''),
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
