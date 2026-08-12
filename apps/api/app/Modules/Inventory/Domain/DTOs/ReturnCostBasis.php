<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\DTOs;

final readonly class ReturnCostBasis
{
    public const SOURCE_EXIT_MOVEMENT = 'exit_movement';

    public const SOURCE_CURRENT_COST = 'current_cost';

    /**
     * @param  numeric-string  $unitCost
     * @param  self::SOURCE_*  $source
     * @param  list<string>  $movementIds
     */
    public function __construct(
        public string $unitCost,
        public string $source,
        public array $movementIds,
    ) {}
}
