<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\AgedPayablesData;
use App\Modules\Accounting\Application\DTOs\Reports\AgedPayablesLineData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AgedPayablesService
 *
 * Generates aged payables (accounts payable aging) reports.
 *
 * Shows outstanding supplier invoices grouped by aging buckets:
 * - Current (0-30 days)
 * - 31-60 days
 * - 61-90 days
 * - Over 90 days
 *
 * Aging is calculated from the invoice due date (or invoice date if no due date).
 *
 * @package App\Modules\Accounting\Application\Services\Reports
 */
final readonly class AgedPayablesService
{
    private const DECIMAL_SCALE = 4;
    private const ZERO_THRESHOLD = '0.0001';

    /**
     * Generate aged payables report.
     *
     * @param string $companyId The company ID
     * @param Carbon|null $asOfDate The snapshot date (defaults to today)
     * @return AgedPayablesData The aged payables report
     */
    public function generate(
        string $companyId,
        ?Carbon $asOfDate = null
    ): AgedPayablesData {
        $asOfDate = $asOfDate ?? Carbon::today();

        // Get outstanding supplier invoices
        $invoices = $this->getOutstandingInvoices($companyId, $asOfDate);

        // Group by vendor and calculate aging buckets
        $vendorBalances = $this->calculateVendorAging($invoices, $asOfDate);

        // Convert to line data objects
        $lines = $vendorBalances->map(function ($balance) {
            return AgedPayablesLineData::from([
                'vendor_id' => $balance['vendor_id'],
                'vendor_name' => $balance['vendor_name'],
                'current' => $balance['current'],
                'days_30' => $balance['days_30'],
                'days_60' => $balance['days_60'],
                'days_90' => $balance['days_90'],
                'over_90' => $balance['over_90'],
                'total' => $balance['total'],
            ]);
        })->values()->toArray();

        // Calculate totals
        $totals = $this->calculateTotals($lines);

        return AgedPayablesData::from([
            'as_of_date' => $asOfDate->toDateString(),
            'lines' => $lines,
            'total_current' => $totals['current'],
            'total_days_30' => $totals['days_30'],
            'total_days_60' => $totals['days_60'],
            'total_days_90' => $totals['days_90'],
            'total_over_90' => $totals['over_90'],
            'grand_total' => $totals['total'],
        ]);
    }

    /**
     * Get outstanding supplier purchase invoices as of a date.
     *
     * Returns invoices that:
     * - Are posted (status = 'posted')
     * - Are purchase invoices (type = 'purchase_invoice')
     * - Have a remaining balance > 0
     * - Invoice date <= as_of_date
     *
     * @param string $companyId
     * @param Carbon $asOfDate
     * @return Collection<Document>
     */
    private function getOutstandingInvoices(string $companyId, Carbon $asOfDate): Collection
    {
        return Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::PurchaseInvoice)
            ->where('status', DocumentStatus::Posted)
            ->where('issue_date', '<=', $asOfDate)
            ->whereColumn('total_amount', '>', DB::raw('COALESCE(paid_amount, 0)'))
            ->with(['partner:id,name,type'])
            ->get();
    }

    /**
     * Calculate aging buckets for each vendor.
     *
     * Groups invoices by vendor and calculates the amount in each aging bucket
     * based on days overdue from due_date (or issue_date if no due_date).
     *
     * Aging buckets:
     * - current: 0-30 days
     * - days_30: 31-60 days
     * - days_60: 61-90 days
     * - days_90: 91-120 days
     * - over_90: >120 days
     *
     * @param Collection<Document> $invoices
     * @param Carbon $asOfDate
     * @return Collection<array>
     */
    private function calculateVendorAging(Collection $invoices, Carbon $asOfDate): Collection
    {
        return $invoices
            ->groupBy('partner_id')
            ->map(function (Collection $vendorInvoices, string $partnerId) use ($asOfDate) {
                $vendor = $vendorInvoices->first()->partner;

                $buckets = [
                    'current' => '0.0000',
                    'days_30' => '0.0000',
                    'days_60' => '0.0000',
                    'days_90' => '0.0000',
                    'over_90' => '0.0000',
                ];

                foreach ($vendorInvoices as $invoice) {
                    $balance = bcsub(
                        $invoice->total_amount,
                        $invoice->paid_amount ?? '0.0000',
                        self::DECIMAL_SCALE
                    );

                    // Calculate days overdue from due_date or issue_date
                    $referenceDate = $invoice->due_date ?? $invoice->issue_date;
                    $daysOverdue = $asOfDate->diffInDays(Carbon::parse($referenceDate), false);

                    // Assign to appropriate bucket
                    $bucket = $this->determineBucket($daysOverdue);
                    $buckets[$bucket] = bcadd($buckets[$bucket], $balance, self::DECIMAL_SCALE);
                }

                $total = array_reduce(
                    $buckets,
                    fn($carry, $amount) => bcadd($carry, $amount, self::DECIMAL_SCALE),
                    '0.0000'
                );

                return [
                    'vendor_id' => $partnerId,
                    'vendor_name' => $vendor->name,
                    'current' => $buckets['current'],
                    'days_30' => $buckets['days_30'],
                    'days_60' => $buckets['days_60'],
                    'days_90' => $buckets['days_90'],
                    'over_90' => $buckets['over_90'],
                    'total' => $total,
                ];
            })
            ->sortByDesc('total');
    }

    /**
     * Determine which aging bucket a balance belongs to based on days overdue.
     *
     * @param int $daysOverdue Positive = overdue, Negative = not due yet
     * @return string The bucket key ('current', 'days_30', etc.)
     */
    private function determineBucket(int $daysOverdue): string
    {
        if ($daysOverdue < 0 || $daysOverdue <= 30) {
            return 'current';
        }

        if ($daysOverdue <= 60) {
            return 'days_30';
        }

        if ($daysOverdue <= 90) {
            return 'days_60';
        }

        if ($daysOverdue <= 120) {
            return 'days_90';
        }

        return 'over_90';
    }

    /**
     * Calculate total amounts across all aging buckets.
     *
     * @param array<AgedPayablesLineData> $lines
     * @return array<string, string>
     */
    private function calculateTotals(array $lines): array
    {
        $totals = [
            'current' => '0.0000',
            'days_30' => '0.0000',
            'days_60' => '0.0000',
            'days_90' => '0.0000',
            'over_90' => '0.0000',
            'total' => '0.0000',
        ];

        foreach ($lines as $line) {
            $totals['current'] = bcadd($totals['current'], $line->current, self::DECIMAL_SCALE);
            $totals['days_30'] = bcadd($totals['days_30'], $line->days_30, self::DECIMAL_SCALE);
            $totals['days_60'] = bcadd($totals['days_60'], $line->days_60, self::DECIMAL_SCALE);
            $totals['days_90'] = bcadd($totals['days_90'], $line->days_90, self::DECIMAL_SCALE);
            $totals['over_90'] = bcadd($totals['over_90'], $line->over_90, self::DECIMAL_SCALE);
            $totals['total'] = bcadd($totals['total'], $line->total, self::DECIMAL_SCALE);
        }

        return $totals;
    }
}
