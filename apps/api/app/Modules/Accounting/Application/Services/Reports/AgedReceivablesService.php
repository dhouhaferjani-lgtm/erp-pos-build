<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\AgedReceivablesData;
use App\Modules\Accounting\Application\DTOs\Reports\AgedReceivablesLineData;
use App\Modules\Accounting\Application\DTOs\Reports\LocationReportBucketData;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * AgedReceivablesService
 *
 * Generates aged receivables (accounts receivable aging) reports.
 *
 * Shows outstanding customer invoices grouped by aging buckets:
 * - Current (0-30 days)
 * - 31-60 days
 * - 61-90 days
 * - Over 90 days
 *
 * Aging is calculated from the invoice due date (or invoice date if no due date).
 */
final readonly class AgedReceivablesService
{
    private const DECIMAL_SCALE = 4;

    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Generate aged receivables report.
     *
     * @param  string  $companyId  The company ID
     * @param  Carbon|null  $asOfDate  The snapshot date (defaults to today)
     * @return AgedReceivablesData The aged receivables report
     */
    /**
     * @param  list<string>  $locationIds
     */
    public function generate(
        string $companyId,
        ?Carbon $asOfDate = null,
        array $locationIds = [],
        bool $groupByLocation = false,
    ): AgedReceivablesData {
        $asOfDate = $asOfDate ?? Carbon::today();
        $scale = $this->currencyScale($companyId);

        // Get outstanding customer invoices
        $invoices = $this->getOutstandingInvoices($companyId, $asOfDate, $scale, $locationIds);

        // Group by customer and calculate aging buckets
        $customerBalances = $this->calculateCustomerAging($invoices, $asOfDate, $scale);

        // Convert to line data objects
        $lines = $customerBalances->map(function ($balance) {
            return AgedReceivablesLineData::from([
                'customer_id' => $balance['customer_id'],
                'customer_name' => $balance['customer_name'],
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

        return AgedReceivablesData::from([
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
     * Get outstanding customer invoices as of a date.
     *
     * Returns invoices that:
     * - Are posted (status = 'posted')
     * - Are for customers (partner_type = 'customer')
     * - Have a remaining balance > 0
     * - Invoice date <= as_of_date
     *
     * @return Collection<int, Document>
     */
    /**
     * @param  list<string>  $locationIds
     * @return Collection<int, Document>
     */
    private function getOutstandingInvoices(string $companyId, Carbon $asOfDate, int $scale, array $locationIds = []): Collection
    {
        $query = Document::query()
            ->where('company_id', $companyId)
            // C-1: an OPENING credit note joins the arm as a negative.
            //
            // The type filter used to be `Invoice` alone, so a historical AR
            // opening credit note — what a NEGATIVE `opening_balance_customer` in
            // the standard Parties CSV becomes, by sign
            // (`Import\Services\PartiesRowMapper::balancePayload()`, named not
            // imported: module boundaries are enforced by deptrac and a docblock is
            // not a dependency) — was invisible here while `GL 411` and the partner
            // page both netted it. The report overstated what was collectible.
            //
            // Narrowed to `is_historical` DELIBERATELY. An ordinary credit note
            // reaches this report through `credit_note_allocations`, which
            // `Document::outstandingBalance()` already subtracts from the invoice it
            // was applied to; admitting it here as a second, standalone negative
            // would double the credit. An OPENING credit note has no invoice to
            // attach to — it IS an open item, and nothing else nets it.
            ->where(static function (Builder $q): void {
                $q->where('type', DocumentType::Invoice)
                    ->orWhere(static function (Builder $creditNotes): void {
                        $creditNotes->where('type', DocumentType::CreditNote)
                            ->where('is_historical', true);
                    });
            })
            ->where('status', DocumentStatus::Posted)
            ->where('document_date', '<=', $asOfDate)
            ->whereOutstanding();
        if ($locationIds !== []) {
            $query->whereIn('location_id', $locationIds);
        }

        return $query
            ->with(['partner:id,name,type', 'allocations:id,document_id,amount', 'creditsAgainstDocument:id,invoice_id,amount'])
            ->get()
            // The SQL predicate is only a coarse bound — SQLite evaluates it in
            // floating point — so bcmath has the final say on what is outstanding.
            // A credit note is kept while it still carries UNAPPLIED credit, so the
            // comparison is against the signed amount's magnitude, not `> 0`.
            ->filter(fn (Document $invoice): bool => bccomp($this->openBalance($invoice, $scale), '0', $scale) !== 0)
            ->values();
    }

    /**
     * The amount this report presents for a document.
     *
     * W-6 D2: it used to be `$invoice->balance_due`, a PostgreSQL trigger cache
     * fired by `payment_allocations` / `credit_note_allocations` DML ONLY. A posted
     * invoice that was never allocated against has no trigger event, so its cache
     * stays NULL forever — 165 invoices / 59 532.410 TND invisible to this report
     * on the demo tenant, against a reported grand total of 32 892.422. Reading
     * the computed outstanding removes the cache from the critical path entirely.
     *
     * @return numeric-string
     */
    private function openBalance(Document $invoice, int $scale): string
    {
        if ($invoice->type === DocumentType::CreditNote) {
            return $this->remainingCreditAsNegative($invoice, $scale);
        }

        return $invoice->outstandingBalance($scale);
    }

    /**
     * C-1 — what an OPENING credit note still owes back, as a NEGATIVE.
     *
     * `Document::outstandingBalance()` is deliberately invoice-oriented: its SQL
     * always joins `credit_note_allocations` on `invoice_id`, "so a credit note's
     * own outward allocations never reduce its balance"
     * (`Document.php:695-708`). For a credit note that is the wrong number — it
     * reports the full face value even after the credit has been handed to an
     * invoice. Subtracting the outward allocations here keeps the report from
     * granting the same credit twice.
     *
     * ONE query per historical credit note, and the branch is reachable only for
     * `is_historical` credit notes (see `getOutstandingInvoices()`), so the bound is
     * the number of opening credit-note ROWS the tenant imported — not the document
     * table.
     *
     * @return numeric-string
     */
    private function remainingCreditAsNegative(Document $creditNote, int $scale): string
    {
        $appliedSum = CreditNoteAllocation::query()
            ->where('credit_note_id', $creditNote->id)
            ->sum('amount');

        /** @var numeric-string $applied */
        $applied = bcadd('0', (string) $appliedSum, $scale);

        /** @var numeric-string $remaining */
        $remaining = bcsub($creditNote->outstandingBalance($scale), $applied, $scale);

        /** @var numeric-string $signed */
        $signed = bcmul($remaining, '-1', $scale);

        return $signed;
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
     * Calculate aging buckets for each customer.
     *
     * Groups invoices by customer and calculates the amount in each aging bucket
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
     * @return Collection<int|string, array{customer_id: string, customer_name: string, current: string, days_30: string, days_60: string, days_90: string, over_90: string, total: string}>
     */
    private function calculateCustomerAging(Collection $invoices, Carbon $asOfDate, int $scale): Collection
    {
        return $invoices
            ->groupBy('partner_id')
            ->map(function (Collection $customerInvoices, string $partnerId) use ($asOfDate, $scale) {
                /** @var Document $firstInvoice */
                $firstInvoice = $customerInvoices->first();
                $customer = $firstInvoice->partner;

                $buckets = [
                    'current' => '0.0000',
                    'days_30' => '0.0000',
                    'days_60' => '0.0000',
                    'days_90' => '0.0000',
                    'over_90' => '0.0000',
                ];

                foreach ($customerInvoices as $invoice) {
                    $balance = $this->openBalance($invoice, $scale);

                    // Days overdue from due_date (or document_date when there is
                    // none). W-6 D4: Carbon's signed `diffInDays` returns
                    // ARGUMENT − RECEIVER, so the previous
                    // `$asOfDate->diffInDays($reference, false)` yielded
                    // `reference − asOf` — NEGATIVE for an invoice that is already
                    // overdue, which `determineBucket()` maps to `current`. Every
                    // overdue receivable was reported as Current and a not-yet-due
                    // one was aged as if late. The receiver must be the REFERENCE
                    // date so the result is `asOf − reference`: positive = overdue.
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
                    'customer_id' => $partnerId,
                    'customer_name' => $customer->name,
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
     * Calculate total amounts across all aging buckets.
     *
     * @param  array<AgedReceivablesLineData>  $lines
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
