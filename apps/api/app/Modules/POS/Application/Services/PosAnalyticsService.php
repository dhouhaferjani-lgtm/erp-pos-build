<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Services\LocationScopeBoundary;
use App\Modules\POS\Application\DTOs\CustomerAnalyticsData;
use App\Modules\POS\Application\DTOs\DiscountAnalysisData;
use App\Modules\POS\Application\DTOs\FnbMetricsData;
use App\Modules\POS\Application\DTOs\SalesSummaryData;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PosAnalyticsService
{
    public function __construct(
        private readonly LocationScopeBoundary $locationScopeBoundary,
    ) {}

    /**
     * Sales summary: counts, totals, averages, payment breakdown.
     *
     * @param  list<string>  $locationIds
     */
    public function getSalesSummary(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): SalesSummaryData
    {
        $receipts = DB::table('pos_receipts')
            ->where('company_id', $companyId)
            ->where('is_voided', false)
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'location_id'))
            ->selectRaw('COUNT(*) as receipt_count')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(total), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as tax_total')
            ->selectRaw("COALESCE(AVG(CASE WHEN receipt_type = 'sale' THEN total END), 0) as average_ticket")
            ->selectRaw("COUNT(CASE WHEN receipt_type = 'return' THEN 1 END) as refund_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN receipt_type = 'return' THEN ABS(total) END), 0) as refund_total")
            ->first();

        /** @var object{receipt_count: int, gross_sales: string, net_sales: string, tax_total: string, average_ticket: string, refund_count: int, refund_total: string} $receipts */
        $receipts = $receipts ?? (object) ['receipt_count' => 0, 'gross_sales' => '0', 'net_sales' => '0', 'tax_total' => '0', 'average_ticket' => '0', 'refund_count' => 0, 'refund_total' => '0'];

        $voidedCount = DB::table('pos_receipts')
            ->where('company_id', $companyId)
            ->where('is_voided', true)
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'location_id'))
            ->count();

        $payments = DB::table('pos_receipt_payments')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_payments.receipt_id')
            ->where('pos_receipts.company_id', $companyId)
            ->where('pos_receipts.is_voided', false)
            ->whereBetween('pos_receipts.posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'pos_receipts.location_id'))
            ->groupBy('pos_receipt_payments.payment_type')
            ->selectRaw('pos_receipt_payments.payment_type')
            ->selectRaw('COALESCE(SUM(pos_receipt_payments.amount), 0) as total')
            ->selectRaw('COUNT(*) as count')
            ->get();

        $paymentBreakdown = $payments->map(fn (object $row) => [
            'payment_type' => $row->payment_type,
            'total' => (string) $row->total,
            'count' => (int) $row->count,
        ])->all();

        return new SalesSummaryData(
            receipt_count: (int) $receipts->receipt_count,
            gross_sales: (string) $receipts->gross_sales,
            net_sales: (string) $receipts->net_sales,
            tax_total: (string) $receipts->tax_total,
            average_ticket: (string) round((float) $receipts->average_ticket, 2),
            refund_count: (int) $receipts->refund_count,
            refund_total: (string) $receipts->refund_total,
            voided_count: $voidedCount,
            payment_breakdown: $paymentBreakdown,
        );
    }

    /**
     * Sales grouped by product category.
     *
     * @param  list<string>  $locationIds
     * @return array<int, array{category_name: string, total: string, count: int}>
     */
    public function getSalesByCategory(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): array
    {
        $rows = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->leftJoin('products', 'products.id', '=', 'pos_receipt_lines.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('pos_receipts.company_id', $companyId)
            ->where('pos_receipts.is_voided', false)
            ->whereBetween('pos_receipts.posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'pos_receipts.location_id'))
            ->groupBy('categories.name')
            ->selectRaw("COALESCE(categories.name, 'Uncategorized') as category_name")
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.line_total), 0) as total')
            ->selectRaw('COUNT(*) as count')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn (object $row) => [
            'category_name' => $row->category_name,
            'total' => (string) $row->total,
            'count' => (int) $row->count,
        ])->all();
    }

    /**
     * Top products by sales volume.
     *
     * @param  list<string>  $locationIds
     * @return array<int, array{product_name: string, total: string, quantity: string}>
     */
    public function getSalesByProduct(string $companyId, CarbonImmutable $from, CarbonImmutable $to, int $limit = 20, array $locationIds = []): array
    {
        $rows = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->where('pos_receipts.company_id', $companyId)
            ->where('pos_receipts.is_voided', false)
            ->whereBetween('pos_receipts.posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'pos_receipts.location_id'))
            ->groupBy('pos_receipt_lines.product_name')
            ->selectRaw('pos_receipt_lines.product_name')
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.line_total), 0) as total')
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.quantity), 0) as quantity')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return $rows->map(fn (object $row) => [
            'product_name' => $row->product_name,
            'total' => (string) $row->total,
            'quantity' => (string) $row->quantity,
        ])->all();
    }

    /**
     * Sales grouped by time period (hour, day, week, month).
     *
     * @param  list<string>  $locationIds
     * @return array<int, array{period: string, total: string, count: int}>
     */
    public function getSalesByTimePeriod(string $companyId, CarbonImmutable $from, CarbonImmutable $to, string $granularity = 'day', array $locationIds = []): array
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        $truncExpr = $isSqlite
            ? $this->sqliteDateTrunc($granularity)
            : $this->pgsqlDateTrunc($granularity);

        $rows = DB::table('pos_receipts')
            ->where('company_id', $companyId)
            ->where('is_voided', false)
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'location_id'))
            ->groupByRaw($truncExpr)
            ->selectRaw("{$truncExpr} as period")
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->selectRaw('COUNT(*) as count')
            ->orderBy('period')
            ->get();

        return $rows->map(fn (object $row) => [
            'period' => (string) $row->period,
            'total' => (string) $row->total,
            'count' => (int) $row->count,
        ])->all();
    }

    /**
     * Cashier performance metrics.
     *
     * @param  list<string>  $locationIds
     * @return array<int, array{cashier_id: string, cashier_name: string, receipt_count: int, total_sales: string, average_ticket: string}>
     */
    public function getCashierPerformance(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): array
    {
        $rows = DB::table('pos_receipts')
            ->where('company_id', $companyId)
            ->where('is_voided', false)
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'location_id'))
            ->groupBy('cashier_id', 'cashier_name')
            ->selectRaw('cashier_id')
            ->selectRaw('cashier_name')
            ->selectRaw('COUNT(*) as receipt_count')
            ->selectRaw('COALESCE(SUM(total), 0) as total_sales')
            ->selectRaw('COALESCE(AVG(total), 0) as average_ticket')
            ->orderByDesc('total_sales')
            ->get();

        return $rows->map(fn (object $row) => [
            'cashier_id' => $row->cashier_id,
            'cashier_name' => $row->cashier_name,
            'receipt_count' => (int) $row->receipt_count,
            'total_sales' => (string) $row->total_sales,
            'average_ticket' => (string) round((float) $row->average_ticket, 2),
        ])->all();
    }

    /**
     * Discount analysis: totals, by reason, top discounted products.
     */
    /** @param list<string> $locationIds */
    public function getDiscountAnalysis(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): DiscountAnalysisData
    {
        $totals = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->where('pos_receipts.company_id', $companyId)
            ->where('pos_receipts.is_voided', false)
            ->whereBetween('pos_receipts.posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'pos_receipts.location_id'))
            ->where('pos_receipt_lines.discount_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.discount_amount), 0) as total_discount_amount')
            ->selectRaw('COUNT(*) as discount_count')
            ->first();

        /** @var object{total_discount_amount: string, discount_count: int} $totals */
        $totals = $totals ?? (object) ['total_discount_amount' => '0', 'discount_count' => 0];

        $byReason = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->where('pos_receipts.company_id', $companyId)
            ->where('pos_receipts.is_voided', false)
            ->whereBetween('pos_receipts.posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'pos_receipts.location_id'))
            ->where('pos_receipt_lines.discount_amount', '>', 0)
            ->groupBy('pos_receipt_lines.discount_reason')
            ->selectRaw("COALESCE(pos_receipt_lines.discount_reason, 'No reason') as reason")
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.discount_amount), 0) as total_amount')
            ->selectRaw('COUNT(*) as count')
            ->orderByDesc('total_amount')
            ->get();

        $topProducts = DB::table('pos_receipt_lines')
            ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
            ->where('pos_receipts.company_id', $companyId)
            ->where('pos_receipts.is_voided', false)
            ->whereBetween('pos_receipts.posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'pos_receipts.location_id'))
            ->where('pos_receipt_lines.discount_amount', '>', 0)
            ->groupBy('pos_receipt_lines.product_name')
            ->selectRaw('pos_receipt_lines.product_name')
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.discount_amount), 0) as discount_amount')
            ->selectRaw('COALESCE(SUM(pos_receipt_lines.quantity), 0) as quantity')
            ->orderByDesc('discount_amount')
            ->limit(10)
            ->get();

        return new DiscountAnalysisData(
            total_discount_amount: (string) $totals->total_discount_amount,
            discount_count: (int) $totals->discount_count,
            by_reason: $byReason->map(fn (object $row) => [
                'reason' => $row->reason,
                'total_amount' => (string) $row->total_amount,
                'count' => (int) $row->count,
            ])->all(),
            top_discounted_products: $topProducts->map(fn (object $row) => [
                'product_name' => $row->product_name,
                'discount_amount' => (string) $row->discount_amount,
                'quantity' => (int) $row->quantity,
            ])->all(),
        );
    }

    /**
     * Customer analytics: unique, returning, top customers.
     */
    /** @param list<string> $locationIds */
    public function getCustomerAnalytics(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): CustomerAnalyticsData
    {
        $base = DB::table('pos_receipts')
            ->where('company_id', $companyId)
            ->where('is_voided', false)
            ->whereBetween('posted_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'location_id'))
            ->whereNotNull('partner_id');

        $uniqueCustomers = (clone $base)->distinct('partner_id')->count('partner_id');

        $returningCount = DB::query()
            ->fromSub(
                (clone $base)
                    ->groupBy('partner_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->selectRaw('partner_id'),
                'returning'
            )
            ->count();

        $returningRate = $uniqueCustomers > 0
            ? (string) round(($returningCount / $uniqueCustomers) * 100, 2)
            : '0';

        $topCustomers = (clone $base)
            ->groupBy('partner_id', 'customer_name')
            ->selectRaw('partner_id')
            ->selectRaw("COALESCE(customer_name, 'Anonymous') as customer_name")
            ->selectRaw('COALESCE(SUM(total), 0) as total_spent')
            ->selectRaw('COUNT(*) as receipt_count')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get();

        return new CustomerAnalyticsData(
            unique_customers: $uniqueCustomers,
            returning_count: $returningCount,
            returning_rate: $returningRate,
            top_customers: $topCustomers->map(fn (object $row) => [
                'partner_id' => $row->partner_id,
                'customer_name' => $row->customer_name,
                'total_spent' => (string) $row->total_spent,
                'receipt_count' => (int) $row->receipt_count,
            ])->all(),
        );
    }

    /**
     * F&B-specific metrics: table time, items per order, peak hours.
     */
    /** @param list<string> $locationIds */
    public function getFnbMetrics(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = [], bool $includeUnattributed = false): FnbMetricsData
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        $closedOrders = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('status', 'closed')
            ->whereBetween('opened_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'location_id', true, $includeUnattributed))
            ->whereNotNull('closed_at');

        $avgTimeExpr = $isSqlite
            ? 'COALESCE(AVG((julianday(closed_at) - julianday(opened_at)) * 1440), 0)'
            : 'COALESCE(AVG(EXTRACT(EPOCH FROM (closed_at - opened_at)) / 60), 0)';

        $avgTableTime = (clone $closedOrders)
            ->selectRaw("{$avgTimeExpr} as avg_minutes")
            ->value('avg_minutes');

        $lineCountsSub = DB::table('pos_order_lines')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_lines.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', 'closed')
            ->whereBetween('pos_orders.opened_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'pos_orders.location_id', true, $includeUnattributed))
            ->groupBy('pos_order_lines.order_id')
            ->selectRaw('COUNT(*) as line_count');

        $avgItemsPerOrder = DB::query()
            ->fromSub($lineCountsSub, 'line_counts')
            ->selectRaw('COALESCE(AVG(line_counts.line_count), 0) as avg_items')
            ->value('avg_items');

        $hourExpr = $isSqlite
            ? "CAST(strftime('%H', opened_at) AS INTEGER)"
            : 'EXTRACT(HOUR FROM opened_at)::integer';

        $peakHours = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('status', 'closed')
            ->whereBetween('opened_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'location_id', true, $includeUnattributed))
            ->groupByRaw($hourExpr)
            ->selectRaw("{$hourExpr} as hour")
            ->selectRaw('COUNT(*) as order_count')
            ->orderByDesc('order_count')
            ->get();

        $ordersByMode = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('status', 'closed')
            ->whereBetween('opened_at', [$from->startOfDay(), $to->endOfDay()])
            ->where($this->locationScope($companyId, $locationIds, 'location_id', true, $includeUnattributed))
            ->whereNotNull('consumption_mode')
            ->groupBy('consumption_mode')
            ->selectRaw('consumption_mode as mode')
            ->selectRaw('COUNT(*) as count')
            ->orderByDesc('count')
            ->get();

        return new FnbMetricsData(
            avg_table_time_minutes: (string) round((float) $avgTableTime, 1),
            avg_items_per_order: (string) round((float) $avgItemsPerOrder, 1),
            peak_hours: $peakHours->map(fn (object $row) => [
                'hour' => (int) $row->hour,
                'order_count' => (int) $row->order_count,
            ])->all(),
            orders_by_mode: $ordersByMode->map(fn (object $row) => [
                'mode' => $row->mode,
                'count' => (int) $row->count,
            ])->all(),
        );
    }

    private function pgsqlDateTrunc(string $granularity): string
    {
        return match ($granularity) {
            'hour' => "DATE_TRUNC('hour', posted_at)",
            'week' => "DATE_TRUNC('week', posted_at)",
            'month' => "DATE_TRUNC('month', posted_at)",
            default => "DATE_TRUNC('day', posted_at)",
        };
    }

    private function sqliteDateTrunc(string $granularity): string
    {
        return match ($granularity) {
            'hour' => "strftime('%Y-%m-%d %H:00:00', posted_at)",
            'week' => "date(posted_at, 'weekday 0', '-6 days')",
            'month' => "strftime('%Y-%m-01', posted_at)",
            default => 'date(posted_at)',
        };
    }

    /**
     * Build a fail-closed location predicate. NULL is visible only to an
     * unrestricted caller and only for nullable location columns.
     *
     * @param  list<string>  $locationIds
     */
    private function locationScope(string $companyId, array $locationIds, string $column, bool $includeNull = false, ?bool $unrestrictedOverride = null): Closure
    {
        $unrestricted = $unrestrictedOverride ?? $this->locationScopeBoundary->isUnrestricted($companyId, $locationIds);

        return static function (Builder $query) use ($locationIds, $column, $includeNull, $unrestricted): void {
            $query->whereIn($column, $locationIds);
            if ($includeNull && $unrestricted) {
                $query->orWhereNull($column);
            }
        };
    }
}
