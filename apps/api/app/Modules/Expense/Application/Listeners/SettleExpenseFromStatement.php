<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\Listeners;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\DTOs\PayExpenseRequestData;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\Events\ExpenseSettlementRequestedFromStatement;
use DomainException;

final readonly class SettleExpenseFromStatement
{
    public function __construct(private ExpenseService $expenses) {}

    public function handle(ExpenseSettlementRequestedFromStatement $event): void
    {
        $expense = Document::query()
            ->where('tenant_id', $event->tenantId)
            ->where('company_id', $event->companyId)
            ->where('type', DocumentType::Expense)
            ->whereKey($event->expenseId)
            ->first();
        $user = User::query()->where('tenant_id', $event->tenantId)->find($event->userId);
        if (! $expense instanceof Document || ! $user instanceof User) {
            throw new DomainException('The statement expense settlement target was not found.');
        }
        $this->expenses->settle(
            $expense,
            new PayExpenseRequestData(
                paymentRepositoryId: $event->repositoryId,
                paymentMethodId: $event->paymentMethodId,
                paymentDate: $event->paymentDate,
            ),
            $user,
        );
    }
}
