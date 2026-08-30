<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use App\Modules\Import\Domain\Enums\DuplicateBucket;

final readonly class DuplicateRowDecisionData
{
    public function __construct(
        public DuplicateBucket $bucket,
        public ?int $winnerRowNumber,
        public bool $locationUnresolved = false,
    ) {}
}
