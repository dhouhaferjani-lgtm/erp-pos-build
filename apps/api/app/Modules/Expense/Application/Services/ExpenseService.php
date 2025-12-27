<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\User;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing expenses.
 */
final class ExpenseService
{
    public function __construct(
        private readonly GeneralLedgerService $glService
    ) {}

    /**
     * Create a new expense.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): Document
    {
        return DB::transaction(function () use ($data, $user): Document {
            // Create the expense document
            $expense = Document::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $data['company_id'],
                'type' => DocumentType::Expense,
                'status' => DocumentStatus::Draft,
                'document_date' => $data['payment_date'] ?? now()->toDateString(),
                'total' => $data['total'] ?? '0.00',
                'subtotal' => $data['total'] ?? '0.00',
                'notes' => $data['notes'] ?? null,
            ]);

            // Create expense metadata
            ExpenseMetadata::create([
                'document_id' => $expense->id,
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'payment_repository_id' => $data['payment_repository_id'] ?? null,
                'payment_date' => $data['payment_date'] ?? null,
                'is_paid' => $data['is_paid'] ?? true,
                'receipt_number' => $data['receipt_number'] ?? null,
                'vendor_name' => $data['vendor_name'] ?? null,
            ]);

            return $expense->load('expenseMetadata');
        });
    }

    /**
     * Update an existing expense.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Document $expense, array $data): Document
    {
        if ($expense->status !== DocumentStatus::Draft) {
            throw new \RuntimeException('Only draft expenses can be updated');
        }

        return DB::transaction(function () use ($expense, $data): Document {
            // Update document
            $expense->update([
                'document_date' => $data['payment_date'] ?? $expense->document_date,
                'total' => $data['total'] ?? $expense->total,
                'subtotal' => $data['total'] ?? $expense->subtotal,
                'notes' => $data['notes'] ?? $expense->notes,
            ]);

            // Update metadata
            $expense->expenseMetadata?->update([
                'expense_category_id' => $data['expense_category_id'] ?? $expense->expenseMetadata->expense_category_id,
                'payment_method_id' => $data['payment_method_id'] ?? $expense->expenseMetadata->payment_method_id,
                'payment_repository_id' => $data['payment_repository_id'] ?? $expense->expenseMetadata->payment_repository_id,
                'payment_date' => $data['payment_date'] ?? $expense->expenseMetadata->payment_date,
                'is_paid' => $data['is_paid'] ?? $expense->expenseMetadata->is_paid,
                'receipt_number' => $data['receipt_number'] ?? $expense->expenseMetadata->receipt_number,
                'vendor_name' => $data['vendor_name'] ?? $expense->expenseMetadata->vendor_name,
            ]);

            $freshExpense = $expense->fresh(['expenseMetadata']);
            if ($freshExpense === null) {
                throw new \RuntimeException('Failed to refresh expense after update');
            }

            return $freshExpense;
        });
    }

    /**
     * Post an expense and create GL entries.
     */
    public function post(Document $expense, User $user): Document
    {
        if ($expense->status !== DocumentStatus::Draft) {
            throw new \RuntimeException('Only draft expenses can be posted');
        }

        return DB::transaction(function () use ($expense, $user): Document {
            // Generate document number
            $expense->document_number = $this->generateExpenseNumber($expense->company_id);
            $expense->status = DocumentStatus::Posted;
            $expense->save();

            // Create GL entry
            $this->glService->createFromExpense($expense, $user);

            $freshExpense = $expense->fresh(['expenseMetadata']);
            if ($freshExpense === null) {
                throw new \RuntimeException('Failed to refresh expense after posting');
            }

            return $freshExpense;
        });
    }

    /**
     * Generate expense document number.
     */
    private function generateExpenseNumber(string $companyId): string
    {
        $year = date('Y');
        $lastExpense = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::Expense)
            ->where('document_number', 'like', "EXP-{$year}-%")
            ->orderByDesc('document_number')
            ->first();

        if ($lastExpense !== null) {
            $lastNumber = (int) substr($lastExpense->document_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('EXP-%s-%06d', $year, $nextNumber);
    }
}
