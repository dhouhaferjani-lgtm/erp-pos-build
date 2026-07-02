<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\UpcomingPaymentLineData;
use App\Modules\Accounting\Application\DTOs\Reports\UpcomingPaymentsData;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Carbon\CarbonImmutable;
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
        $outgoing = $this->openDocuments($companyId, [DocumentType::SupplierInvoice, DocumentType::Expense], $windowEnd);

        $incomingLines = $this->toLines($incoming, $today);
        $outgoingLines = $this->toLines($outgoing, $today);

        $totalIn = $this->sum($incoming, $scale);
        $totalOut = $this->sum($outgoing, $scale);

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

                return new UpcomingPaymentLineData(
                    partner_name: $partner instanceof Partner ? $partner->name : 'Unassigned',
                    document_number: $document->document_number,
                    type: $document->type->value,
                    due_date: $dueDate->toDateString(),
                    balance_due: $document->balance_due ?? '0',
                    days_until_due: $daysUntilDue,
                    overdue: $daysUntilDue < 0,
                );
            })
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
            $total = bcadd($total, $document->balance_due ?? '0', $documentScale);
        }

        return CurrencyScale::bcformat($total, $scale);
    }
}
