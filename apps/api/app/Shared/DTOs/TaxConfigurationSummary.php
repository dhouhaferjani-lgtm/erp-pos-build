<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class TaxConfigurationSummary
{
    public function __construct(
        public string $id,
        public ?string $percentageRate,
    ) {}
}
