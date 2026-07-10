<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReplenishmentRequestData extends Data
{
    /** @param numeric-string|null $requestedQty */
    public function __construct(
        public readonly string $id,
        public readonly string $locationId,
        public readonly string $locationName,
        public readonly string $productId,
        public readonly string $productName,
        public readonly ?string $variantId,
        public readonly ?string $variantName,
        public readonly ?string $requestedQty,
        public readonly ?string $note,
        public readonly int $requestCount,
        public readonly string $status,
        public readonly string $sourceChannel,
        public readonly string $firstRequestedAt,
        public readonly string $lastRequestedAt,
        public readonly ?string $sourcingDocumentId,
        public readonly ?string $fulfillmentType,
        public readonly ?string $fulfillmentId,
        public readonly ?string $rejectionReason,
    ) {}
}
