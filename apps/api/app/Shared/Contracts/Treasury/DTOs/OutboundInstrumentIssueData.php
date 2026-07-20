<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\DTOs;

final readonly class OutboundInstrumentIssueData
{
    /** @param numeric-string $amount */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $paymentMethodId,
        public string $kind,
        public string $reference,
        public string $amount,
        public string $currency,
        public string $repositoryId,
        public string $issueDate,
        public string $createdBy,
        public ?string $partnerId = null,
        public ?string $drawerName = null,
        public ?string $maturityDate = null,
        public ?string $bankId = null,
        public ?string $bankName = null,
        public ?string $bankBranch = null,
        public ?string $bankAccount = null,
        public ?string $idempotencyPrefix = null,
    ) {}
}
