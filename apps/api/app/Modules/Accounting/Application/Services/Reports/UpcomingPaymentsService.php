<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\UpcomingPaymentLineData;
use App\Modules\Accounting\Application\DTOs\Reports\UpcomingPaymentsData;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class UpcomingPaymentsService
{
    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function generate(string $companyId, int $days): UpcomingPaymentsData
    {
        $company = Company::query()->findOrFail($companyId);
        $scale = $this->scaleResolver->getScale((string) $company->currency);
        $today = CarbonImmutable::today();
        $windowEnd = $today->addDays($days);

        $incoming = $this->openDocuments($companyId, [DocumentType::Invoice], $windowEnd);
        $outgoing = $this->openDocuments($companyId, [DocumentType::SupplierInvoice], $windowEnd)
            ->concat($this->openUnpaidExpenses($companyId, $windowEnd))
            ->sortBy(fn (Document $document): string => sprintf(
                '%s|%s',
                CarbonImmutable::parse($document->due_date ?? $document->document_date)->toDateString(),
                (string) $document->document_number,
            ))
            ->values();
        $incomingInstruments = $this->pendingInstruments($companyId, InstrumentDirection::Inbound, $windowEnd);
        $outgoingInstruments = $this->pendingInstruments($companyId, InstrumentDirection::Outbound, $windowEnd);

        $incomingLines = $this->sortLines([
            ...$this->toLines($incoming, $today),
            ...$this->instrumentLines($incomingInstruments, $today),
        ]);
        $outgoingLines = $this->sortLines([
            ...$this->toLines($outgoing, $today),
            ...$this->instrumentLines($outgoingInstruments, $today),
        ]);

        $totalIn = bcadd($this->sum($incoming, $scale), $this->sumInstruments($incomingInstruments, $scale), $scale);
        $totalOut = bcadd($this->sum($outgoing, $scale), $this->sumInstruments($outgoingInstruments, $scale), $scale);

        return new UpcomingPaymentsData(
            in: $incomingLines,
            out: $outgoingLines,
            total_in: $totalIn,
            total_out: $totalOut,
            net: bcsub($totalIn, $totalOut, $scale),
            days: $days,
            as_of_date: $today->toDateString(),
        );
    }

    /** @return Collection<int, PaymentInstrument> */
    private function pendingInstruments(
        string $companyId,
        InstrumentDirection $direction,
        CarbonImmutable $windowEnd,
    ): Collection {
        return PaymentInstrument::query()
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
            ->orderBy('reference')
            ->get();
    }

    /**
     * @param  list<DocumentType>  $types
     * @return Collection<int, Document>
     */
    private function openDocuments(string $companyId, array $types, CarbonImmutable $windowEnd): Collection
    {
        return Document::query()
            ->where('company_id', $companyId)
            ->whereIn('type', $types)
            ->where('status', DocumentStatus::Posted)
            ->where('balance_due', '>', 0)
            ->whereRaw('COALESCE(due_date, document_date) <= ?', [$windowEnd->toDateString()])
            ->with(['partner:id,name'])
            ->orderByRaw('COALESCE(due_date, document_date) ASC')
            ->orderBy('document_number')
            ->get();
    }

    /**
     * Expense documents never populate balance_due (no payment_allocations),
     * so open expenses are sourced from expense_metadata.is_paid instead.
     *
     * @return Collection<int, Document>
     */
    private function openUnpaidExpenses(string $companyId, CarbonImmutable $windowEnd): Collection
    {
        return Document::query()
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
            ->orderBy('document_number')
            ->get();
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
                    document_number: $document->document_number,
                    type: $document->type->value,
                    due_date: $dueDate->toDateString(),
                    balance_due: $this->openAmount($document),
                    days_until_due: $daysUntilDue,
                    overdue: $daysUntilDue < 0,
                    source: 'document',
                    certainty: null,
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
