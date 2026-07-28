<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

final readonly class ReplayBatchResult
{
    public function __construct(
        public ReplayComputation $computation,
        public bool $hasMovementNear,
    ) {}
}
