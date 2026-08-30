<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\PartnerIdentityResolutionData;

interface PartnerResolverInterface
{
    public function resolve(
        string $tenantId,
        string $companyId,
        ?string $code,
        ?string $vatNumber,
        string $name,
    ): PartnerIdentityResolutionData;
}
