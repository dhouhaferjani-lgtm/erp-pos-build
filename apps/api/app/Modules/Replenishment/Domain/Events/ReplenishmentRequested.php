<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Events;

use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplenishmentRequested
{
    use Dispatchable;

    /** @param numeric-string|null $requestedQty */
    public function __construct(
        public readonly string $requestId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly ?string $requestedQty,
        public readonly string $requestedByUserId,
        public readonly ReplenishmentChannel $channel,
        public readonly string $occurredAt,
    ) {}
}
