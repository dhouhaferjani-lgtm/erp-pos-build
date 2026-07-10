<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ReplenishmentRequestBumped
{
    use Dispatchable;

    /** @param numeric-string|null $requestedQty */
    public function __construct(
        public readonly string $requestId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly ?string $requestedQty,
        public readonly int $requestCount,
        public readonly string $occurredAt,
    ) {}
}
