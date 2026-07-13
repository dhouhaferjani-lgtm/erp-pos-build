<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Application\Queries\ExpenseIndexQuery;
use App\Modules\Expense\Domain\ExpenseMetadata;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExpenseExportController extends Controller
{
    private const HEADERS = [
        'document_number',
        'document_date',
        'partner/vendor',
        'category',
        'status',
        'is_paid',
        'subtotal',
        'vat_amount',
        'vat_deductible_percent',
        'total',
        'currency',
        'receipt_number',
    ];

    public function __construct(
        private readonly ExpenseIndexQuery $expenseIndexQuery,
        private readonly CompanyContext $companyContext,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $expenses = $this->expenseIndexQuery->build($request, $companyId)
            ->leftJoin('expense_metadata as export_metadata', 'export_metadata.document_id', '=', 'documents.id')
            ->leftJoin('expense_categories as export_category', function (JoinClause $join) use ($companyId): void {
                $join->on('export_category.id', '=', 'export_metadata.expense_category_id')
                    ->where('export_category.company_id', '=', $companyId);
            })
            ->leftJoin('partners as export_partner', function (JoinClause $join) use ($companyId): void {
                $join->on('export_partner.id', '=', 'documents.partner_id')
                    ->where('export_partner.company_id', '=', $companyId);
            })
            ->select([
                'documents.id',
                'documents.document_number',
                'documents.document_date',
                'documents.status',
                'documents.subtotal',
                'documents.tax_amount',
                'documents.total',
                'documents.currency',
                'export_partner.name as export_partner_name',
                'export_metadata.vendor_name as export_vendor_name',
                'export_category.name as export_category_name',
                'export_metadata.is_paid as export_is_paid',
                'export_metadata.vat_deductible_percent as export_vat_deductible_percent',
                'export_metadata.receipt_number as export_receipt_number',
            ])
            ->orderBy('documents.document_date')
            ->orderBy('documents.created_at')
            ->orderBy('documents.id');

        $dateFrom = $request->filled('date_from') ? (string) $request->input('date_from') : 'all';
        $dateTo = $request->filled('date_to') ? (string) $request->input('date_to') : 'all';
        $filename = "expenses-{$dateFrom}-{$dateTo}.csv";

        return response()->streamDownload(function () use ($expenses): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS, ',', '"', '');

            foreach ($expenses->cursor() as $expense) {
                fputcsv($output, $this->row($expense), ',', '"', '');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return list<string>
     */
    private function row(Document $expense): array
    {
        $partner = $expense->getAttribute('export_partner_name');
        $vendor = $expense->getAttribute('export_vendor_name');
        $isPaid = $expense->getAttribute('export_is_paid');

        return [
            $expense->document_number,
            $expense->document_date->toDateString(),
            (string) ($partner ?? $vendor ?? ''),
            (string) ($expense->getAttribute('export_category_name') ?? ''),
            $expense->status->value,
            $isPaid === null ? '' : ((bool) $isPaid ? '1' : '0'),
            (string) ($expense->subtotal ?? ''),
            (string) ($expense->tax_amount ?? ''),
            $this->vatDeductiblePercent($expense),
            (string) ($expense->total ?? ''),
            $expense->currency,
            (string) ($expense->getAttribute('export_receipt_number') ?? ''),
        ];
    }

    private function vatDeductiblePercent(Document $expense): string
    {
        $value = $expense->getAttribute('export_vat_deductible_percent');
        if ($value === null) {
            return '';
        }

        // Reuse the metadata decimal cast so SQLite and PostgreSQL expose the
        // stored DECIMAL(5,2) value identically without a float conversion.
        $metadata = new ExpenseMetadata;
        $metadata->setRawAttributes(['vat_deductible_percent' => $value]);

        return (string) $metadata->vat_deductible_percent;
    }
}
