<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

/**
 * Immutable result of replaying one stock grain from its physical-count
 * instant through a caller-supplied observation instant.
 */
final readonly class ReplayComputation
{
    /**
     * @param  numeric-string  $movementsSinceCount
     * @param  numeric-string  $onHandNow
     * @param  numeric-string  $expectedNow
     * @param  numeric-string  $adjustment
     */
    public function __construct(
        public string $movementsSinceCount,
        public string $onHandNow,
        public string $expectedNow,
        public string $adjustment,
    ) {}
}
