<?php

declare(strict_types=1);

namespace App\Modules\Income\Application\Listeners;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Identity\Domain\User;
use App\Modules\Income\Application\Services\IncomeService;
use App\Modules\Treasury\Domain\Events\IncomeCreationRequestedFromStatement;
use DomainException;

final readonly class CreateIncomeFromStatement
{
    public function __construct(private IncomeService $income) {}

    public function handle(IncomeCreationRequestedFromStatement $event): void
    {
        $user = User::query()->where('tenant_id', $event->tenantId)->find($event->userId);
        $accountExists = Account::query()
            ->where('tenant_id', $event->tenantId)
            ->where('company_id', $event->companyId)
            ->where('type', AccountType::Revenue)
            ->where('is_active', true)
            ->whereKey($event->incomeAccountId)
            ->exists();
        if (! $user instanceof User || ! $accountExists) {
            throw new DomainException('The statement income user or revenue account was not found.');
        }
        $income = $this->income->create([
            'company_id' => $event->companyId,
            'location_id' => $event->locationId,
            'total' => $event->amount,
            'document_date' => $event->valueDate,
            'payment_date' => $event->valueDate,
            'payment_repository_id' => $event->repositoryId,
            'income_account_id' => $event->incomeAccountId,
            'source_name' => $event->sourceName,
            'notes' => $event->notes,
            'is_received' => true,
            // income_metadata.idempotency_key is a UUID column. The statement
            // line id is already deterministic and unique to this action type.
            'idempotency_key' => $event->lineId,
        ], $user);
        $income = $this->income->post($income, $user);
        $event->complete($income->id);
    }
}
