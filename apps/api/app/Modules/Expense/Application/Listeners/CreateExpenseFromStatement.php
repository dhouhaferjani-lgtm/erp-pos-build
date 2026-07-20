<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Listeners;

use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\Events\ExpenseCreationRequestedFromStatement;
use DomainException;

final readonly class CreateExpenseFromStatement
{
    public function __construct(private ExpenseService $expenses) {}

    public function handle(ExpenseCreationRequestedFromStatement $event): void
    {
        $user = User::query()->where('tenant_id', $event->tenantId)->find($event->userId);
        if (! $user instanceof User) {
            throw new DomainException('The statement expense creation user was not found.');
        }
        if ($event->expenseCategoryId !== null && ! ExpenseCategory::query()
            ->where('tenant_id', $event->tenantId)
            ->where('company_id', $event->companyId)
            ->whereKey($event->expenseCategoryId)
            ->exists()) {
            throw new DomainException('The statement expense category does not belong to the active company.');
        }
        $expense = $this->expenses->create([
            'company_id' => $event->companyId,
            'location_id' => $event->locationId,
            'total' => $event->amount,
            'document_date' => $event->valueDate,
            'payment_date' => $event->valueDate,
            'payment_repository_id' => $event->repositoryId,
            'expense_category_id' => $event->expenseCategoryId,
            'vendor_name' => $event->vendorName,
            'notes' => $event->notes,
            'is_paid' => true,
            'idempotency_key' => "stmtline:{$event->lineId}:create_expense",
        ], $user);
        $expense = $this->expenses->post($expense, $user);
        $event->complete($expense->id);
    }
}
