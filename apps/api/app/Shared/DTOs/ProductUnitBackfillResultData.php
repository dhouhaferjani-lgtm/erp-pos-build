<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class ProductUnitBackfillResultData
{
    public function __construct(
        public int $mapped,
        public int $ambiguous,
        public int $unknown,
        public int $missingCompany = 0,
    ) {}
}
