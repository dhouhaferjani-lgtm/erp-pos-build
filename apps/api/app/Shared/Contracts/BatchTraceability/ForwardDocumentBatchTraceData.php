<?php

declare(strict_types=1);

namespace App\Shared\Contracts\BatchTraceability;

final readonly class ForwardDocumentBatchTraceData
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $documentNumber,
        public readonly string $documentType,
        public readonly string $documentDate,
        public readonly string $partnerName,
        public readonly ?string $partnerId,
        public readonly string $productName,
        public readonly string $quantity,
    ) {}
}
