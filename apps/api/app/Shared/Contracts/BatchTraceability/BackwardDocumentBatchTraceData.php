<?php

declare(strict_types=1);

namespace App\Shared\Contracts\BatchTraceability;

final readonly class BackwardDocumentBatchTraceData
{
    public function __construct(
        public readonly ?string $batchNumber,
        public readonly int $batchId,
        public readonly ?string $expiryDate,
        public readonly bool $isRecalled,
        public readonly bool $isExpired,
        public readonly string $productName,
        public readonly string $productId,
        public readonly string $quantity,
        public readonly string $documentNumber,
        public readonly string $documentType,
        public readonly string $documentDate,
    ) {}
}
