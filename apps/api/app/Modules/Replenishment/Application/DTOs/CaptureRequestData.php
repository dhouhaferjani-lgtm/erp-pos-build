<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Application\DTOs;

use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;

final class CaptureRequestData
{
    /** @param numeric-string|null $requestedQty */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly ?string $requestedQty,
        public readonly ?string $note,
        public readonly string $requestedByUserId,
        public readonly ReplenishmentChannel $channel,
        public readonly ?string $clientRequestUuid = null,
    ) {}
}
