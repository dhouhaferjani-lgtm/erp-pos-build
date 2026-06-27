<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\DTOs;

/**
 * One line of a grouped write-off: a specific batch (lot) and the positive
 * quantity to write off from it.
 */
final readonly class GroupedWriteOffLine
{
    /**
     * @param  numeric-string  $quantity  Positive quantity to write off (canonical scale 4)
     */
    public function __construct(
        public int $batchId,
        public string $quantity,
    ) {}
}
