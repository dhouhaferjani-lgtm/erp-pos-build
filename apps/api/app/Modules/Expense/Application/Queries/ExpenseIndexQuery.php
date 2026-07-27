<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Queries;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Builds the company-scoped expense list query shared by JSON and CSV surfaces.
 */
final class ExpenseIndexQuery
{
    /**
     * @param  list<string>  $locationIds
     * @return Builder<Document>
     */
    public function build(Request $request, string $companyId, array $locationIds = []): Builder
    {
        $query = Document::query()
            ->where('documents.type', DocumentType::Expense)
            ->where('documents.company_id', $companyId);

        if ($locationIds !== []) {
            $query->whereIn('documents.location_id', $locationIds);
        }

        if ($request->filled('status')) {
            $query->where('documents.status', $request->input('status'));
        }

        if ($request->filled('category_id')) {
            $query->whereRelation(
                'expenseMetadata',
                'expense_category_id',
                $request->input('category_id'),
            );
        }

        if ($request->filled('date_from')) {
            $query->where('documents.document_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $exclusiveUpperBound = Carbon::parse((string) $request->input('date_to'))
                ->addDay()
                ->toDateString();
            $query->where('documents.document_date', '<', $exclusiveUpperBound);
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $documentQuery) use ($search): void {
                $documentQuery->whereLike('documents.document_number', "%{$search}%", caseSensitive: false)
                    ->orWhereHas('expenseMetadata', function (Builder $metadataQuery) use ($search): void {
                        $metadataQuery->whereLike('vendor_name', "%{$search}%", caseSensitive: false)
                            ->orWhereLike('receipt_number', "%{$search}%", caseSensitive: false);
                    });
            });
        }

        return $query;
    }
}
