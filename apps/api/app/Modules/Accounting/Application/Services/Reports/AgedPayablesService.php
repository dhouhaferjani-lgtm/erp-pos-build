<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\AgedPayablesData;
use App\Modules\Accounting\Application\DTOs\Reports\AgedPayablesLineData;
use App\Modules\Accounting\Application\DTOs\Reports\LocationReportBucketData;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
 */
final readonly class AgedPayablesService
{
    private const DECIMAL_SCALE = 4;

    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Generate aged payables report.
     *
     * @param  string  $companyId  The company ID
     * @param  Carbon|null  $asOfDate  The snapshot date (defaults to today)
     * @return AgedPayablesData The aged payables report
     */
    /**
     * @param  list<string>  $locationIds
     */
    public function generate(
        string $companyId,
        ?Carbon $asOfDate = null,
        array $locationIds = [],
        bool $groupByLocation = false,
    ): AgedPayablesData {
        $asOfDate = $asOfDate ?? Carbon::today();
        $scale = $this->currencyScale($companyId);

        // Get outstanding supplier invoices
        $invoices = $this->getOutstandingInvoices($companyId, $asOfDate, $scale, $locationIds);

        // Group by vendor and calculate aging buckets
        $vendorBalances = $this->calculateVendorAging($invoices, $asOfDate, $scale);

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
        })->values()->all();

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
            'buckets_by_location' => $groupByLocation ? $this->locationBuckets($invoices, $companyId, $scale) : [],
        ]);
    }

    /**
     * @param  Collection<int, Document>  $invoices
     * @return list<LocationReportBucketData>
     */
    private function locationBuckets(Collection $invoices, string $companyId, int $scale): array
    {
        $buckets = [];
        foreach ($invoices as $invoice) {
            $key = $invoice->location_id ?? 'unattributed';
            $buckets[$key] ??= [
                'location_id' => $invoice->location_id,
                'total' => '0.0000',
            ];
            $buckets[$key]['total'] = bcadd(
                $buckets[$key]['total'],
                $this->openBalance($invoice, $scale),
                self::DECIMAL_SCALE,
            );
        }

        $locationIds = array_values(array_filter(
            array_keys($buckets),
            static fn (string|int $id): bool => $id !== 'unattributed',
        ));
        $names = Location::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $locationIds)
            ->pluck('name', 'id');

        return array_values(array_map(
            static fn (array $bucket): LocationReportBucketData => LocationReportBucketData::from([
                'location_id' => $bucket['location_id'],
                'location_name' => $bucket['location_id'] === null
                    ? 'Unattributed'
                    : (string) ($names->get($bucket['location_id']) ?? $bucket['location_id']),
                'total' => $bucket['total'],
            ]),
            $buckets,
        ));
    }

    /**
     * Get outstanding supplier purchase orders as of a date.
     *
     * Received auto-generated POs are valued at their uninvoiced posted-receipt
     * accrual, not their PO balance_due. Normal received POs are deliberately
     * excluded so the report does not surface phantom payables.
     *
     * @return Collection<int, Document>
     */
    /**
     * @param  list<string>  $locationIds
     * @return Collection<int, Document>
     */
    private function getOutstandingInvoices(string $companyId, Carbon $asOfDate, int $scale, array $locationIds = []): Collection
    {
        $postedQuery = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::PurchaseOrder)
            ->where('status', DocumentStatus::Posted)
            ->where('document_date', '<=', $asOfDate)
            ->whereOutstanding();
        if ($locationIds !== []) {
            $postedQuery->whereIn('location_id', $locationIds);
        }
        $postedPurchaseOrders = $postedQuery
            ->with(['partner:id,name,type', 'allocations:id,document_id,amount', 'creditsAgainstDocument:id,invoice_id,amount'])
            ->get()
            // The SQL predicate is only a coarse bound — SQLite evaluates it in
            // floating point — so bcmath has the final say on what is outstanding.
            ->filter(static fn (Document $purchaseOrder): bool => bccomp($purchaseOrder->outstandingBalance($scale), '0', $scale) > 0)
            ->values();

        $autoReceivedPurchaseOrders = $this->autoGeneratedReceivedPurchaseOrdersWithAccrual($companyId, $asOfDate, $locationIds);

        return $postedPurchaseOrders->concat($autoReceivedPurchaseOrders)->values();
    }

    /**
     * @return Collection<int, Document>
     */
    /**
     * @param  list<string>  $locationIds
     * @return Collection<int, Document>
     */
    private function autoGeneratedReceivedPurchaseOrdersWithAccrual(string $companyId, Carbon $asOfDate, array $locationIds = []): Collection
    {
        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);
        $scale = $this->scaleResolver->getScale((string) $company->currency);
        $calculationScale = $scale + 4;

        $query = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::PurchaseOrder)
            ->where('status', DocumentStatus::Received)
            ->where('document_date', '<=', $asOfDate)
            ->whereJsonContainsKey('payload->auto_generated');
        if ($locationIds !== []) {
            $query->whereIn('location_id', $locationIds);
        }
        $purchaseOrders = $query->with(['partner:id,name,type', 'lines'])->get();

        /** @var Collection<int, Document> */
        return $purchaseOrders
            ->map(function (Document $purchaseOrder) use ($calculationScale, $scale): ?Document {
                $poLineIds = $purchaseOrder->lines->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
                if ($poLineIds === []) {
                    return null;
                }

                $balance = '0';
                $receiptLines = GoodsReceiptLine::query()
                    ->postedReceipts()
                    ->whereIn('po_line_id', $poLineIds)
                    ->get();

                foreach ($receiptLines as $receiptLine) {
                    if ($receiptLine->accrual_unit_cost === null) {
                        continue;
                    }

                    /** @var numeric-string $uninvoicedQty */
                    $uninvoicedQty = bcsub((string) $receiptLine->received_qty, (string) $receiptLine->quantity_invoiced, $calculationScale);
                    if (bccomp($uninvoicedQty, '0', $calculationScale) <= 0) {
                        continue;
                    }

                    /** @var numeric-string $lineValue */
                    $lineValue = bcmul((string) $receiptLine->accrual_unit_cost, $uninvoicedQty, $calculationScale);
                    $balance = bcadd($balance, $lineValue, $calculationScale);
                }

                if (bccomp($balance, '0', $scale) <= 0) {
                    return null;
                }

                $purchaseOrder->balance_due = CurrencyScale::bcformatStrict($balance, $scale);

                return $purchaseOrder;
            })
            ->filter()
            ->values();
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
     * @param  Collection<int, Document>  $invoices
     * @param  int  $scale  The company currency's scale (CLAUDE.md rule 19)
     * @return Collection<int|string, array{vendor_id: string, vendor_name: string, current: string, days_30: string, days_60: string, days_90: string, over_90: string, total: string}>
     */
    private function calculateVendorAging(Collection $invoices, Carbon $asOfDate, int $scale): Collection
    {
        return $invoices
            ->groupBy('partner_id')
            ->map(function (Collection $vendorInvoices, string $partnerId) use ($asOfDate, $scale) {
                /** @var Document $firstInvoice */
                $firstInvoice = $vendorInvoices->first();
                $vendor = $firstInvoice->partner;

                $buckets = [
                    'current' => '0.0000',
                    'days_30' => '0.0000',
                    'days_60' => '0.0000',
                    'days_90' => '0.0000',
                    'over_90' => '0.0000',
                ];

                foreach ($vendorInvoices as $invoice) {
                    $balance = $this->openBalance($invoice, $scale);

                    // Days overdue from due_date (or document_date when there is
                    // none). W-6 D4: Carbon's signed `diffInDays` returns
                    // ARGUMENT − RECEIVER, so the previous
                    // `$asOfDate->diffInDays($reference, false)` yielded
                    // `reference − asOf` — NEGATIVE for a payable that is already
                    // overdue, which `determineBucket()` maps to `current`. The
                    // receiver must be the REFERENCE date so the result is
                    // `asOf − reference`: positive = overdue. Byte-identical to the
                    // AR sibling, which carried the same inversion.
                    $referenceDate = $invoice->due_date ?? $invoice->document_date;
                    $daysOverdue = (int) Carbon::parse($referenceDate)->diffInDays($asOfDate, false);

                    // Assign to appropriate bucket
                    $bucket = $this->determineBucket($daysOverdue);
                    $buckets[$bucket] = bcadd($buckets[$bucket], $balance, self::DECIMAL_SCALE);
                }

                $total = array_reduce(
                    $buckets,
                    fn ($carry, $amount) => bcadd($carry, $amount, self::DECIMAL_SCALE),
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
     * @param  int  $daysOverdue  Positive = overdue, Negative = not due yet
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
     * The amount this report presents for a document.
     *
     * Two distinct valuations live in this collection and they must not be mixed:
     *
     * - A `Received` auto-generated PO is valued at its UNINVOICED posted-receipt
     *   accrual, which {@see autoGeneratedReceivedPurchaseOrdersWithAccrual()}
     *   hydrates onto `balance_due` in memory (the model is never saved). The PO's
     *   own allocations say nothing about that accrual, so the computed outstanding
     *   would be wrong here.
     * - Everything else — the `Posted` POs — is the computed outstanding.
     *
     * W-6 D2: the second case used to read `balance_due` too, a PostgreSQL trigger
     * cache fired by allocation DML ONLY, so a posted PO that was never allocated
     * against kept a NULL cache forever and never reached this report.
     *
     * @return numeric-string
     */
    private function openBalance(Document $document, int $scale): string
    {
        if ($document->status === DocumentStatus::Received) {
            /** @var numeric-string */
            return (string) ($document->balance_due ?? '0');
        }

        return $document->outstandingBalance($scale);
    }

    /**
     * The scale the outstanding arithmetic runs at: the COMPANY's currency
     * (CLAUDE.md rule 19 — never a bare no-arg resolve).
     */
    private function currencyScale(string $companyId): int
    {
        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);

        return $this->scaleResolver->getScaleSafe((string) $company->currency, 3);
    }

    /**
     * Calculate total amounts across all aging buckets.
     *
     * @param  array<AgedPayablesLineData>  $lines
     * @return array<string, string>
     */
    private function calculateTotals(array $lines): array
    {
        /** @var array<string, numeric-string> $totals */
        $totals = [
            'current' => '0.0000',
            'days_30' => '0.0000',
            'days_60' => '0.0000',
            'days_90' => '0.0000',
            'over_90' => '0.0000',
            'total' => '0.0000',
        ];

        foreach ($lines as $line) {
            /** @var numeric-string $lineCurrent */
            $lineCurrent = $line->current;
            /** @var numeric-string $lineDays30 */
            $lineDays30 = $line->days_30;
            /** @var numeric-string $lineDays60 */
            $lineDays60 = $line->days_60;
            /** @var numeric-string $lineDays90 */
            $lineDays90 = $line->days_90;
            /** @var numeric-string $lineOver90 */
            $lineOver90 = $line->over_90;
            /** @var numeric-string $lineTotal */
            $lineTotal = $line->total;
            $totals['current'] = bcadd($totals['current'], $lineCurrent, self::DECIMAL_SCALE);
            $totals['days_30'] = bcadd($totals['days_30'], $lineDays30, self::DECIMAL_SCALE);
            $totals['days_60'] = bcadd($totals['days_60'], $lineDays60, self::DECIMAL_SCALE);
            $totals['days_90'] = bcadd($totals['days_90'], $lineDays90, self::DECIMAL_SCALE);
            $totals['over_90'] = bcadd($totals['over_90'], $lineOver90, self::DECIMAL_SCALE);
            $totals['total'] = bcadd($totals['total'], $lineTotal, self::DECIMAL_SCALE);
        }

        return $totals;
    }
}
