<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Shared\Contracts\PartnerResolverInterface;
use App\Shared\DTOs\PartnerIdentityResolutionData;

final readonly class PartnerResolver implements PartnerResolverInterface
{
    public function __construct(private PartnerService $partners) {}

    public function resolve(
        string $tenantId,
        string $companyId,
        ?string $code,
        ?string $vatNumber,
        string $name,
    ): PartnerIdentityResolutionData {
        return $this->partners->resolveIdentity(
            $tenantId,
            $companyId,
            self::blankToNull($code),
            self::blankToNull($vatNumber),
            trim($name),
        );
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
