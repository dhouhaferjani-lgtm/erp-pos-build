<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

final readonly class ExpenseSettlementRequestedFromStatement
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $expenseId,
        public string $repositoryId,
        public ?string $paymentMethodId,
        public string $paymentDate,
        public string $userId,
    ) {}
}
