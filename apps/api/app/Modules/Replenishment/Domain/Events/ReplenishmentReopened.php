<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ReplenishmentReopened
{
    use Dispatchable;

    public function __construct(
        public readonly string $requestId,
        public readonly string $cancelledTransferId,
        public readonly string $occurredAt,
    ) {}
}
