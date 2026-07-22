<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\LocationReportBucketData;
use App\Modules\Accounting\Application\DTOs\Reports\UpcomingPaymentLineData;
use App\Modules\Accounting\Application\DTOs\Reports\UpcomingPaymentsData;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Expense\Domain\Services\RecurrenceCursor;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * This report follows its established cross-module read style: it queries
 * ExpenseMetadata and ExpenseRecurrenceTemplate directly for read-only feeds.
 */
final readonly class UpcomingPaymentsService
{
    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /** @param list<string> $locationIds */
    public function generate(string $companyId, int $days, array $locationIds = [], bool $groupByLocation = false): UpcomingPaymentsData
    {
        $company = Company::query()->findOrFail($companyId);
        $scale = $this->scaleResolver->getScale((string) $company->currency);
        $today = CarbonImmutable::today();
        $windowEnd = $today->addDays($days);

        $incoming = $this->openDocuments($companyId, [DocumentType::Invoice], $windowEnd, $locationIds);
        $outgoing = $this->openDocuments($companyId, [DocumentType::SupplierInvoice], $windowEnd, $locationIds)
            ->concat($this->openUnpaidExpenses($companyId, $windowEnd, $locationIds))
            ->concat($this->materializedRecurringDrafts($companyId, $windowEnd, $locationIds))
            ->sortBy(fn (Document $document): string => sprintf(
                '%s|%s',
                CarbonImmutable::parse($document->due_date ?? $document->document_date)->toDateString(),
                (string) $document->document_number,
            ))
            ->values();
        $projectedRecurring = $this->projectedRecurringOccurrences(
            $company->tenant_id,
            $companyId,
            $today,
            $windowEnd,
            $scale,
            $locationIds,
        );
        $incomingInstruments = $this->pendingInstruments($companyId, InstrumentDirection::Inbound, $windowEnd, $locationIds);
        $outgoingInstruments = $this->pendingInstruments($companyId, InstrumentDirection::Outbound, $windowEnd, $locationIds);

        $incomingLines = $this->sortLines([
            ...$this->toLines($incoming, $today),
            ...$this->instrumentLines($incomingInstruments, $today),
        ]);
        $outgoingLines = $this->sortLines([
            ...$this->toLines($outgoing, $today),
            ...$projectedRecurring['lines'],
            ...$this->instrumentLines($outgoingInstruments, $today),
        ]);

        $totalIn = bcadd($this->sum($incoming, $scale), $this->sumInstruments($incomingInstruments, $scale), $scale);
        $totalOut = bcadd(
            bcadd($this->sum($outgoing, $scale), $projectedRecurring['total'], $scale),
            $this->sumInstruments($outgoingInstruments, $scale),
            $scale,
        );

        return new UpcomingPaymentsData(
            in: $incomingLines,
            out: $outgoingLines,
            total_in: $totalIn,
            total_out: $totalOut,
            net: bcsub($totalIn, $totalOut, $scale),
            days: $days,
            as_of_date: $today->toDateString(),
            buckets_by_location: $groupByLocation
                ? $this->locationBuckets($incomingLines, $outgoingLines, $companyId, $scale)
                : [],
        );
    }

    /**
     * @param  list<UpcomingPaymentLineData>  $incoming
     * @param  list<UpcomingPaymentLineData>  $outgoing
     * @return list<LocationReportBucketData>
     */
    private function locationBuckets(array $incoming, array $outgoing, string $companyId, int $scale): array
    {
        $zero = CurrencyScale::bcformatStrict('0', $scale);
        /** @var array<string, array{location_id: string|null, total_in: numeric-string, total_out: numeric-string}> $totals */
        $totals = [];

        foreach ([[$incoming, 'total_in'], [$outgoing, 'total_out']] as [$lines, $direction]) {
            foreach ($lines as $line) {
                $locationId = $line->location_id;
                $key = $locationId ?? 'unattributed';
                $totals[$key] ??= [
                    'location_id' => $locationId,
                    'total_in' => $zero,
                    'total_out' => $zero,
                ];
                $totals[$key][$direction] = bcadd($totals[$key][$direction], $line->balance_due, $scale);
            }
        }

        $ids = array_values(array_filter(array_keys($totals), static fn (string $id): bool => $id !== 'unattributed'));
        $names = Location::query()->where('company_id', $companyId)->whereIn('id', $ids)->pluck('name', 'id');

        return array_values(array_map(
            static function (array $total) use ($names, $scale): LocationReportBucketData {
                $locationId = $total['location_id'];
                $totalIn = $total['total_in'];
                $totalOut = $total['total_out'];
                $net = bcsub($totalIn, $totalOut, $scale);

                return new LocationReportBucketData(
                    location_id: $locationId,
                    location_name: $locationId === null ? 'Unattributed' : (string) ($names->get($locationId) ?? $locationId),
                    total: $net,
                    total_in: $totalIn,
                    total_out: $totalOut,
                    net: $net,
                );
            },
            $totals,
        ));
    }

    /**
     * @param  list<string>  $locationIds
     * @return Collection<int, PaymentInstrument>
     */
    private function pendingInstruments(
        string $companyId,
        InstrumentDirection $direction,
        CarbonImmutable $windowEnd,
        array $locationIds = [],
    ): Collection {
        $query = PaymentInstrument::query()
            ->where('company_id', $companyId)
            ->where('direction', $direction)
            ->whereIn('status', [InstrumentStatus::Received, InstrumentStatus::Deposited])
            ->where(function (Builder $query) use ($windowEnd): void {
                /** @var Builder<PaymentInstrument> $query */
                $query->whereNull('maturity_date')
                    ->orWhereDate('maturity_date', '<=', $windowEnd->toDateString());
            })
            ->with(['partner:id,name'])
            ->orderByRaw('maturity_date IS NOT NULL, maturity_date ASC')
            ->orderBy('reference');
        if ($locationIds !== []) {
            $query->whereIn('location_id', $locationIds);
        }

        return $query->get();
    }

    /**
     * @param  list<DocumentType>  $types
     * @return Collection<int, Document>
     */
    /**
     * @param  list<DocumentType>  $types
     * @param  list<string>  $locationIds
     * @return Collection<int, Document>
     */
    private function openDocuments(string $companyId, array $types, CarbonImmutable $windowEnd, array $locationIds = []): Collection
    {
        $query = Document::query()
            ->where('company_id', $companyId)
            ->whereIn('type', $types)
            ->where('status', DocumentStatus::Posted)
            ->where('balance_due', '>', 0)
            ->whereRaw('COALESCE(due_date, document_date) <= ?', [$windowEnd->toDateString()])
            ->with(['partner:id,name'])
            ->orderByRaw('COALESCE(due_date, document_date) ASC')
            ->orderBy('document_number');
        if ($locationIds !== []) {
            $query->whereIn('location_id', $locationIds);
        }

        return $query->get();
    }

    /**
     * Expense documents never populate balance_due (no payment_allocations),
     * so open expenses are sourced from expense_metadata.is_paid instead.
     *
     * @return Collection<int, Document>
     */
    /**
     * @param  list<string>  $locationIds
     * @return Collection<int, Document>
     */
    private function openUnpaidExpenses(string $companyId, CarbonImmutable $windowEnd, array $locationIds = []): Collection
    {
        $query = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::Expense)
            ->where('status', DocumentStatus::Posted)
            ->where('total', '>', 0)
            ->whereHas('expenseMetadata', function (Builder $query): void {
                /** @var Builder<ExpenseMetadata> $query */
                $query->where('is_paid', false);
            })
            ->whereRaw('COALESCE(due_date, document_date) <= ?', [$windowEnd->toDateString()])
            ->with(['partner:id,name', 'expenseMetadata:id,document_id,vendor_name,is_paid'])
            ->orderByRaw('COALESCE(due_date, document_date) ASC')
            ->orderBy('document_number');
        if ($locationIds !== []) {
            $query->whereIn('location_id', $locationIds);
        }

        return $query->get();
    }

    /**
     * Recurring drafts are already concrete outflows, even though they have not
     * yet been posted. Their template cursor has advanced, so excluding them
     * here would create a gap between materialization and posting.
     *
     * @return Collection<int, Document>
     */
    /**
     * @param  list<string>  $locationIds
     * @return Collection<int, Document>
     */
    private function materializedRecurringDrafts(string $companyId, CarbonImmutable $windowEnd, array $locationIds = []): Collection
    {
        $query = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::Expense)
            ->where('status', DocumentStatus::Draft)
            ->where('total', '>', 0)
            ->whereHas('expenseMetadata', function (Builder $query): void {
                /** @var Builder<ExpenseMetadata> $query */
                $query->whereNotNull('recurrence_template_id');
            })
            ->whereDate('document_date', '<=', $windowEnd->toDateString())
            ->with(['partner:id,name', 'expenseMetadata:id,document_id,vendor_name,is_paid,recurrence_template_id'])
            ->orderBy('document_date')
            ->orderBy('id');
        if ($locationIds !== []) {
            $query->whereIn('location_id', $locationIds);
        }

        return $query->get();
    }

    /**
     * Project active template cursors without materializing documents. Cursor
     * advance partitions these lines from materialized drafts and posted bills.
     *
     * @return array{lines: array<int, UpcomingPaymentLineData>, total: numeric-string}
     */
    /**
     * @param  list<string>  $locationIds
     * @return array{lines: array<int, UpcomingPaymentLineData>, total: numeric-string}
     */
    private function projectedRecurringOccurrences(
        string $tenantId,
        string $companyId,
        CarbonImmutable $today,
        CarbonImmutable $windowEnd,
        int $scale,
        array $locationIds = [],
    ): array {
        $lines = [];
        $total = CurrencyScale::bcformat('0', $scale);
        $templates = ExpenseRecurrenceTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('status', RecurrenceStatus::Active)
            ->whereDate('next_due_date', '<=', $windowEnd->toDateString())
            ->when($locationIds !== [], fn ($query) => $query->whereIn(
                'payment_repository_id',
                PaymentRepository::query()->whereIn('location_id', $locationIds)->select('id'),
            ))
            ->with('paymentRepository:id,location_id')
            ->orderBy('next_due_date')
            ->orderBy('id')
            ->get();

        foreach ($templates as $template) {
            $origin = CarbonImmutable::parse($template->start_date->toDateString());
            $dueDate = CarbonImmutable::parse($template->next_due_date->toDateString());
            $endDate = $template->end_date === null
                ? null
                : CarbonImmutable::parse($template->end_date->toDateString());
            $amount = CurrencyScale::bcformat((string) $template->amount, $scale);

            while (
                $dueDate->lessThanOrEqualTo($windowEnd)
                && ($endDate === null || $dueDate->lessThanOrEqualTo($endDate))
            ) {
                $daysUntilDue = (int) $today->diffInDays($dueDate, false);
                $lines[] = new UpcomingPaymentLineData(
                    partner_name: $template->name,
                    document_number: $template->name,
                    type: DocumentType::Expense->value,
                    due_date: $dueDate->toDateString(),
                    balance_due: $amount,
                    days_until_due: $daysUntilDue,
                    overdue: $daysUntilDue < 0,
                    source: 'recurrence_projection',
                    certainty: 'projected',
                    location_id: $template->paymentRepository?->location_id,
                );
                $total = bcadd($total, $amount, $scale);
                $dueDate = RecurrenceCursor::next($origin, $template->frequency, $dueDate);
            }
        }

        return [
            'lines' => $lines,
            'total' => CurrencyScale::bcformat($total, $scale),
        ];
    }

    /**
     * Open amount owed on a document: expenses carry it in total
     * (balance_due is never populated for them), everything else in balance_due.
     *
     * @return numeric-string
     */
    private function openAmount(Document $document): string
    {
        if ($document->type === DocumentType::Expense) {
            /** @var numeric-string */
            return $document->total ?? '0';
        }

        /** @var numeric-string */
        return $document->balance_due ?? '0';
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return array<int, UpcomingPaymentLineData>
     */
    private function toLines(Collection $documents, CarbonImmutable $today): array
    {
        return $documents
            ->map(function (Document $document) use ($today): UpcomingPaymentLineData {
                $dueDate = CarbonImmutable::parse($document->due_date ?? $document->document_date);
                $daysUntilDue = (int) $today->diffInDays($dueDate, false);
                $partner = $document->getRelation('partner');
                $partnerName = $partner instanceof Partner ? $partner->name : null;
                if ($partnerName === null && $document->type === DocumentType::Expense) {
                    $partnerName = $document->expenseMetadata?->vendor_name;
                }

                return new UpcomingPaymentLineData(
                    partner_name: $partnerName ?? 'Unassigned',
                    document_number: $document->document_number ?? $document->id,
                    type: $document->type->value,
                    due_date: $dueDate->toDateString(),
                    balance_due: $this->openAmount($document),
                    days_until_due: $daysUntilDue,
                    overdue: $daysUntilDue < 0,
                    source: 'document',
                    certainty: null,
                    location_id: $document->location_id,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PaymentInstrument>  $instruments
     * @return array<int, UpcomingPaymentLineData>
     */
    private function instrumentLines(Collection $instruments, CarbonImmutable $today): array
    {
        return $instruments
            ->map(function (PaymentInstrument $instrument) use ($today): UpcomingPaymentLineData {
                $dueDate = $instrument->maturity_date === null
                    ? $today
                    : CarbonImmutable::parse($instrument->maturity_date->toDateString());
                $daysUntilDue = (int) $today->diffInDays($dueDate, false);
                $partner = $instrument->getRelation('partner');

                return new UpcomingPaymentLineData(
                    partner_name: $partner instanceof Partner ? $partner->name : 'Unassigned',
                    document_number: $instrument->reference,
                    type: 'instrument',
                    due_date: $dueDate->toDateString(),
                    balance_due: $instrument->amount,
                    days_until_due: $daysUntilDue,
                    overdue: $daysUntilDue < 0,
                    source: 'instrument',
                    certainty: $instrument->status === InstrumentStatus::Deposited ? 'remitted' : 'portfolio',
                    location_id: $instrument->location_id,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, UpcomingPaymentLineData>  $lines
     * @return array<int, UpcomingPaymentLineData>
     */
    private function sortLines(array $lines): array
    {
        return collect($lines)
            ->sortBy(fn (UpcomingPaymentLineData $line): string => $line->due_date.'|'.$line->document_number)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return numeric-string
     */
    private function sum(Collection $documents, int $scale): string
    {
        $total = CurrencyScale::bcformat('0', $scale);

        foreach ($documents as $document) {
            $documentScale = $this->scaleResolver->getScale((string) $document->currency);
            $total = bcadd($total, $this->openAmount($document), $documentScale);
        }

        return CurrencyScale::bcformat($total, $scale);
    }

    /**
     * @param  Collection<int, PaymentInstrument>  $instruments
     * @return numeric-string
     */
    private function sumInstruments(Collection $instruments, int $scale): string
    {
        $total = CurrencyScale::bcformat('0', $scale);
        foreach ($instruments as $instrument) {
            $instrumentScale = $this->scaleResolver->getScale($instrument->currency);
            $total = bcadd($total, $instrument->amount, $instrumentScale);
        }

        return CurrencyScale::bcformat($total, $scale);
    }
}
