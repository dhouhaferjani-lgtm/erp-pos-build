<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

final readonly class MaturityLegContext
{
    public function __construct(
        public string $currency,
        public string $repositoryId,
        public ?string $partnerId,
        public string $receivedDate,
        public ?string $createdBy,
        public ?string $locationId = null,
    ) {}
}
