<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ReplenishmentSourced
{
    use Dispatchable;

    public function __construct(
        public readonly string $requestId,
        public readonly string $sourcingDocumentId,
        public readonly string $processedByUserId,
        public readonly string $occurredAt,
    ) {}
}
