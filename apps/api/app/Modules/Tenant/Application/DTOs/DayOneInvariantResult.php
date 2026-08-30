<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\DTOs;

final readonly class DayOneInvariantResult
{
    public function __construct(
        public string $key,
        public string $description,
        public string $expected,
        public string $actual,
        public bool $passed,
        public string $companyId,
        public ?string $locationId,
    ) {}
}
