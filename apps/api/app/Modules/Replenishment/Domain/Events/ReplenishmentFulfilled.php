<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Events;

use App\Modules\Replenishment\Domain\Enums\ReplenishmentFulfillmentType;
use Illuminate\Foundation\Events\Dispatchable;

final class ReplenishmentFulfilled
{
    use Dispatchable;

    public function __construct(
        public readonly string $requestId,
        public readonly ReplenishmentFulfillmentType $fulfillmentType,
        public readonly string $fulfillmentId,
        public readonly string $processedByUserId,
        public readonly string $occurredAt,
    ) {}
}
