<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

final class IncomeCreationRequestedFromStatement
{
    private ?string $documentId = null;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $lineId,
        public readonly string $repositoryId,
        public readonly ?string $locationId,
        public readonly string $amount,
        public readonly string $valueDate,
        public readonly string $userId,
        public readonly string $incomeAccountId,
        public readonly ?string $sourceName,
        public readonly ?string $notes,
    ) {}

    public function complete(string $documentId): void
    {
        $this->documentId = $documentId;
    }

    public function documentId(): ?string
    {
        return $this->documentId;
    }
}
