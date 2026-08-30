<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use App\Shared\Enums\PartnerIdentityMatch;

final readonly class PartnerIdentityResolutionData
{
    public function __construct(
        public ?string $partnerId,
        public ?string $code,
        public ?PartnerIdentityMatch $matchedBy,
    ) {}
}
