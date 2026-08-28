<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Listeners;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Events\ExpenseStatementSuggestionsRequested;

final class CollectStatementExpenseSuggestions
{
    public function handle(ExpenseStatementSuggestionsRequested $event): void
    {
        $expenses = Document::query()
            ->join('expense_metadata', 'expense_metadata.document_id', '=', 'documents.id')
            ->where('documents.tenant_id', $event->tenantId)
            ->where('documents.company_id', $event->companyId)
            ->where('documents.type', DocumentType::Expense)
            ->where('documents.status', DocumentStatus::Posted)
            ->where('documents.currency', $event->currency)
            ->where('documents.total', $event->amount)
            ->whereRaw(
                'COALESCE(expense_metadata.payment_date, documents.document_date) BETWEEN ? AND ?',
                [$event->windowStart, $event->windowEnd],
            )
            ->where('expense_metadata.payment_repository_id', $event->repositoryId)
            ->where('expense_metadata.is_paid', false)
            ->select('documents.*')
            ->addSelect('expense_metadata.vendor_name as suggestion_vendor_name')
            ->addSelect('expense_metadata.payment_date as suggestion_payment_date')
            ->orderByRaw('COALESCE(expense_metadata.payment_date, documents.document_date)')
            ->orderBy('documents.id')
            ->get();

        foreach ($expenses as $expense) {
            $vendor = $expense->getAttribute('suggestion_vendor_name');
            // R-2 / LEDGER D-T9-1: the label is DISPLAY, and a draft expense carries no
            // number — fall through to the id rather than an empty suggestion label.
            $label = is_string($vendor) && trim($vendor) !== ''
                ? trim($vendor)
                : ($expense->document_number ?? $expense->id);
            $normalizedVendor = mb_strtolower(preg_replace('/\s+/u', ' ', $label) ?? $label);
            $paymentDate = $expense->getAttribute('suggestion_payment_date');
            $candidateDate = is_string($paymentDate) && $paymentDate !== ''
                ? $paymentDate
                : $expense->document_date->toDateString();
            $event->addCandidate(
                expenseId: $expense->id,
                amount: $expense->total ?? '0.000',
                label: $label,
                date: $candidateDate,
                referenceMatched: $normalizedVendor !== '' && str_contains($event->lineText, $normalizedVendor),
            );
        }
    }
}
